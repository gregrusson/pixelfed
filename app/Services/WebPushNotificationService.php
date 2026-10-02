<?php

namespace App\Services;

use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Models\Mention;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\Status;
use App\Models\User;
use App\Models\UserFilter;
use App\Services\WebPush\DeliveryQueueConfiguration;
use App\Services\WebPush\DeliveryService;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebPushNotificationService
{
    // Seven days covers queue retries, duplicate rows and independently processed
    // owner mentions. This is event retention, not the five-minute delivery TTL.
    public const DEDUPE_SECONDS = 604800;

    public static function notify(Notification $notification): void
    {
        $eventType = $notification->action === 'mention' ? 'mention' : 'comment';
        try {
            // Group actions and all other event types are deliberately excluded.
            if (! in_array($notification->action, ['comment', 'mention'], true)
                || ! in_array($notification->item_type, [Status::class, 'App\\Status'], true)) {
                return;
            }
            // The observer already runs after commit; also protect direct callers.
            if ($notification->getConnection()->transactionLevel() > 0) {
                $notification->getConnection()->afterCommit(fn () => self::notify($notification));

                return;
            }
            $notification = $notification->fresh();
            if (! $notification || $notification->trashed()
                || ! in_array($notification->action, ['comment', 'mention'], true)
                || ! in_array($notification->item_type, [Status::class, 'App\\Status'], true)) {
                return;
            }
            $status = Status::find($notification->item_id);
            $actor = Profile::find($notification->actor_id);
            $recipient = Profile::find($notification->profile_id);
            if (! $status || ! $actor || ! $recipient
                || $status->group_id
                || $recipient->domain !== null || ! $recipient->user_id
                || (string) $status->profile_id !== (string) $actor->id
                || (string) $actor->id === (string) $recipient->id) {
                return;
            }
            $parent = $status->in_reply_to_id ? Status::find($status->in_reply_to_id) : null;
            if ($status->in_reply_to_id && (! $parent || $parent->group_id
                || (string) $status->in_reply_to_profile_id !== (string) $parent->profile_id)) {
                return;
            }
            // Classify once, before eligibility. An owner mention remains a
            // comment even when its comment preference or parent rejects it.
            $eventType = self::classify($notification, $status, $recipient, $parent);
            if ($eventType === null) {
                return;
            }
            $user = User::find($recipient->user_id);
            if (! $user || (string) $user->profile_id !== (string) $recipient->id) {
                return;
            }
            $enabled = $eventType === 'comment' ? (bool) $user->notify_comment : (bool) $user->notify_mention;
            if (! $enabled || ! $user->pushSubscriptions()->exists()) {
                return;
            }
            if ($eventType === 'comment') {
                if ((bool) $parent->comments_disabled) {
                    return;
                }
            } elseif (! in_array($status->scope, ['public', 'unlisted', 'private'], true)
                || ! StatusService::isVisibleTo($status->profile_id, $status->scope, $recipient->id)) {
                // Mentioning someone does not itself grant access to a private
                // status. Direct messages and groups use separate flows.
                return;
            }
            // Reuse the same profile-based mute/block records as CommentPipeline.
            if (UserFilter::whereUserId($recipient->id)
                ->whereFilterableType(Profile::class)
                ->whereIn('filter_type', ['mute', 'block'])
                ->whereFilterableId($actor->id)->exists()) {
                return;
            }
            // Only plain account identifiers enter the body (never display names,
            // markup or remote URLs). Remote usernames may include their domain.
            $username = $actor->username;
            $remote = $actor->domain !== null;
            if ($remote && is_string($username) && str_starts_with($username, '@')) {
                // Imported profiles store @name@domain. Remove exactly one marker;
                // a second leading @ must still fail validation below.
                $username = substr($username, 1);
            }
            // Helpers::extractUsername permits remote names beginning with . or -.
            // Keep the local rule unchanged and require an alphanumeric remote name
            // after removing its permitted punctuation, as the importer does.
            $pattern = $remote
                ? '/\A[A-Za-z0-9_.-][A-Za-z0-9_.@-]*\z/D'
                : '/\A[A-Za-z0-9_][A-Za-z0-9_.@-]*\z/D';
            if (! is_string($username) || strlen($username) > 255
                || ! preg_match($pattern, $username)
                || ($remote && ! ctype_alnum(str_replace(['_', '.', '-'], '', explode('@', $username)[0])))) {
                return;
            }
            app(DeliveryService::class)->validateConfiguration(); // Local checks only.
            [$connection, , $ttl] = DeliveryQueueConfiguration::validated();
            // Laravel's dispatcher resolves this same cached queue connection.
            // Resolve local container/connector preparation before claiming events;
            // serialization, queue events and Redis acceptance remain in dispatch.
            $dispatcher = app(Dispatcher::class);
            app(QueueFactory::class)->connection($connection);
            $store = Cache::store('redis')->getStore();
            if (! $store instanceof RedisStore) {
                self::report('redis_dedupe_required', $eventType);

                return;
            }
            $payload = $eventType === 'comment'
                ? self::commentPayload($status, $parent, $actor, $recipient, $username)
                : self::mentionPayload($status, $actor, $username);
            $expiresAt = time() + $ttl;
            foreach ($user->pushSubscriptions()->select('id')->lazyById() as $subscription) {
                $job = (new DeliverWebPush((string) $user->id, (string) $subscription->id, $payload, $expiresAt))->afterCommit();
                $key = 'webpush:'.$eventType.':'.$user->id.':'.$status->id.':'.$subscription->id;
                $claim = $store->lock($key, self::DEDUPE_SECONDS);
                if (! $claim->get()) {
                    continue;
                }
                try {
                    $dispatcher->dispatch($job);
                    // Keep the claim until expiry. Claim-then-dispatch has a small
                    // crash window; strict exactly-once requires a durable outbox.
                } catch (Throwable) {
                    // Dispatch can fail before or after acceptance by Redis.
                    // Keep the claim when that outcome cannot be distinguished.
                    self::report('enqueue_failed', $eventType);
                }
            }
        } catch (Throwable) {
            self::report('orchestration_failed', $eventType ?? 'mention');
        }
    }

    private static function classify(Notification $notification, Status $status, Profile $recipient, ?Status $parent): ?string
    {
        $ownsParent = $parent && (string) $parent->profile_id === (string) $recipient->id;
        if ($notification->action === 'comment') {
            return $ownsParent ? 'comment' : null;
        }
        if ($ownsParent) {
            return 'comment';
        }

        return Mention::whereStatusId($status->id)->whereProfileId($recipient->id)->exists()
            ? 'mention' : null;
    }

    private static function commentPayload(Status $status, Status $parent, Profile $actor, Profile $recipient, string $username): array
    {
        $payload = [
            'notification_type' => 'comment',
            'title' => 'New Comment',
            'body' => '@'.$username.' commented on your post',
            'account_id' => (string) $actor->id,
            'status_id' => (string) $status->id,
            'parent_status_id' => (string) $parent->id,
        ];
        // Direct messages use conversation views, not the normal post route.
        $linkableScopes = ['public', 'unlisted', 'private'];
        if (in_array($parent->scope, $linkableScopes, true) && in_array($status->scope, $linkableScopes, true)
            && ! $parent->uri && preg_match('/\A[A-Za-z0-9_]+\z/D', $recipient->username ?? '')) {
            $payload['url'] = '/p/'.$recipient->username.'/'.$parent->id;
        }

        return $payload;
    }

    private static function mentionPayload(Status $status, Profile $actor, string $username): array
    {
        $payload = [
            'notification_type' => 'mention',
            'title' => 'New Mention',
            'body' => '@'.$username.' mentioned you',
            'account_id' => (string) $actor->id,
            'status_id' => (string) $status->id,
        ];
        // Build only reviewed local destinations. Remote content can still
        // notify, but its SPA route is outside the worker's allowlist.
        if ($actor->domain === null && (bool) $status->local && ! $status->uri
            && preg_match('/\A[A-Za-z0-9_]+\z/D', $actor->username ?? '')
            && preg_match('/\A[0-9]+\z/D', (string) $status->id)) {
            $payload['url'] = '/p/'.$actor->username.'/'.$status->id;
        }

        return $payload;
    }

    private static function report(string $category, string $eventType = 'comment'): void
    {
        try {
            Log::warning('Web Push '.$eventType.': '.$category);
        } catch (Throwable) {
            // Even a broken logger must not disrupt internal notifications.
        }
    }
}

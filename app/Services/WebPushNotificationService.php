<?php

namespace App\Services;

use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Models\Follower;
use App\Models\Like;
use App\Models\Mention;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\Status;
use App\Models\User;
use App\Models\UserFilter;
use App\Services\WebPush\DeliveryQueueConfiguration;
use App\Services\WebPush\DeliveryService;
use App\Services\WebPush\EventSupport;
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
        $eventType = in_array($notification->action, ['mention', 'follow', 'like'], true) ? $notification->action : 'comment';
        try {
            // Group actions and all other event types are deliberately excluded.
            if (! self::supports($notification)) {
                return;
            }
            // The observer already runs after commit; also protect direct callers.
            if ($notification->getConnection()->transactionLevel() > 0) {
                $notification->getConnection()->afterCommit(fn () => self::notify($notification));

                return;
            }
            $notification = $notification->fresh();
            if (! $notification || $notification->trashed()
                || ! self::supports($notification)) {
                return;
            }
            $actor = Profile::find($notification->actor_id);
            $recipient = Profile::find($notification->profile_id);
            if (! $actor || ! $recipient
                || $recipient->domain !== null || ! $recipient->user_id
                || (string) $actor->id === (string) $recipient->id) {
                return;
            }
            if ($notification->action === 'follow') {
                if ((string) $notification->item_id !== (string) $recipient->id
                    || ! preg_match('/\A[0-9]+\z/D', (string) $actor->id)) {
                    return;
                }
                $follower = Follower::whereProfileId($actor->id)->whereFollowingId($recipient->id)->first();
                // Notifications do not bind a follower generation. Reject older
                // notifications, but second-precision timestamps cannot distinguish
                // a stale replay from a re-follow within the same second.
                if (! $follower || ! $notification->created_at || ! $follower->created_at
                    || $notification->created_at->lt($follower->created_at)) {
                    return;
                }
                $eventType = 'follow';
                $generationId = $follower->id;
            } elseif ($notification->action === 'like') {
                $eventType = 'like';
                $status = Status::find($notification->item_id);
                if (! $status || $status->group_id
                    || ! in_array($status->scope, ['public', 'unlisted', 'private'], true)
                    || (string) $status->profile_id !== (string) $recipient->id) {
                    return;
                }
                $like = Like::whereProfileId($actor->id)->whereStatusId($status->id)->first();
                // Like generations are not stored on Notifications. As with follows,
                // equal second-precision timestamps cannot disambiguate a stale row.
                // A delayed old pipeline can also create a newer Notification.
                if (! $like || (string) $like->profile_id !== (string) $actor->id
                    || (string) $like->status_id !== (string) $status->id
                    || ! $notification->created_at || ! $like->created_at
                    || $notification->created_at->lt($like->created_at)) {
                    return;
                }
                $generationId = $like->id;
            } else {
                $status = Status::find($notification->item_id);
                if (! $status || $status->group_id
                    || (string) $status->profile_id !== (string) $actor->id) {
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
                $generationId = $status->id;
            }
            $user = User::find($recipient->user_id);
            if (! $user || (string) $user->profile_id !== (string) $recipient->id) {
                return;
            }
            $enabled = match ($eventType) {
                'comment' => (bool) $user->notify_comment,
                'mention' => (bool) $user->notify_mention,
                'follow' => (bool) $user->notify_follow,
                'like' => (bool) $user->notify_like,
            };
            if (! $enabled || ! $user->pushSubscriptions()->exists()) {
                return;
            }
            if ($eventType === 'comment') {
                if ((bool) $parent->comments_disabled) {
                    return;
                }
            } elseif ($eventType === 'mention' && (! in_array($status->scope, ['public', 'unlisted', 'private'], true)
                || ! StatusService::isVisibleTo($status->profile_id, $status->scope, $recipient->id))) {
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
            $username = EventSupport::normalizeActorUsername($actor);
            if ($username === null) {
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
            $payload = match ($eventType) {
                'comment' => self::commentPayload($status, $parent, $actor, $recipient, $username),
                'mention' => self::mentionPayload($status, $actor, $username),
                'follow' => self::followPayload($actor, $username),
                'like' => self::likePayload($status, $actor, $recipient, $username),
            };
            $expiresAt = time() + $ttl;
            foreach ($user->pushSubscriptions()->select('id')->lazyById() as $subscription) {
                $job = (new DeliverWebPush((string) $user->id, (string) $subscription->id, $payload, $expiresAt))->afterCommit();
                $key = 'webpush:'.$eventType.':'.$user->id.':'.$generationId.':'.$subscription->id;
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

    private static function supports(Notification $notification): bool
    {
        return ($notification->action === 'follow'
            && in_array($notification->item_type, [Profile::class, 'App\\Profile'], true))
            || (in_array($notification->action, ['comment', 'mention', 'like'], true)
                && in_array($notification->item_type, [Status::class, 'App\\Status'], true));
    }

    private static function likePayload(Status $status, Profile $actor, Profile $recipient, string $username): array
    {
        $payload = [
            'notification_type' => 'like',
            'title' => 'New Like',
            'body' => '@'.$username.' liked your post',
            'account_id' => (string) $actor->id,
            'status_id' => (string) $status->id,
        ];
        if ($recipient->domain === null && (string) $status->profile_id === (string) $recipient->id
            && in_array($status->scope, ['public', 'unlisted', 'private'], true) && ! $status->group_id
            && (bool) $status->local && ! $status->uri && ! $status->object_url && ! $status->url
            && preg_match('/\A[A-Za-z0-9_]+\z/D', $recipient->username ?? '')
            && preg_match('/\A[0-9]+\z/D', (string) $status->id)) {
            $payload['url'] = '/p/'.$recipient->username.'/'.$status->id;
        }

        return $payload;
    }

    private static function followPayload(Profile $actor, string $username): array
    {
        return [
            'notification_type' => 'follow',
            'title' => 'New Follower',
            'body' => '@'.$username.' followed you',
            'account_id' => (string) $actor->id,
            'url' => '/i/web/profile/'.$actor->id,
        ];
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

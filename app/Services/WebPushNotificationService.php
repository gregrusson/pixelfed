<?php

namespace App\Services;

use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\Status;
use App\Models\User;
use App\Models\UserFilter;
use App\Services\WebPush\DeliveryQueueConfiguration;
use App\Services\WebPush\DeliveryService;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Bus\Dispatcher;
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
            $comment = Status::find($notification->item_id);
            $parent = $comment?->in_reply_to_id ? Status::find($comment->in_reply_to_id) : null;
            $actor = Profile::find($notification->actor_id);
            $recipient = $parent ? Profile::find($parent->profile_id) : null;
            if (! $comment || ! $parent || ! $actor || ! $recipient
                || $comment->group_id || $parent->group_id
                || $recipient->domain !== null || ! $recipient->user_id
                || (string) $comment->profile_id !== (string) $actor->id
                || (string) $notification->profile_id !== (string) $recipient->id
                || (string) $comment->in_reply_to_profile_id !== (string) $recipient->id
                || (string) $actor->id === (string) $recipient->id
                || (bool) $parent->comments_disabled) {
                return;
            }
            // Both actions now represent a reply to its immediate parent's owner.
            // A mention of anyone else never passes the ownership check above.
            $user = User::find($recipient->user_id);
            if (! $user || (string) $user->profile_id !== (string) $recipient->id
                || ! (bool) $user->notify_comment || ! $user->pushSubscriptions()->exists()) {
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
            if (! is_string($username) || strlen($username) > 255
                || ! preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.@-]*\z/D', $username)) {
                return;
            }
            app(DeliveryService::class)->validateConfiguration(); // Local checks only.
            [, , $ttl] = DeliveryQueueConfiguration::validated();
            $store = Cache::store('redis')->getStore();
            if (! $store instanceof RedisStore) {
                self::report('redis_dedupe_required');

                return;
            }
            $payload = [
                'notification_type' => 'comment',
                'title' => 'New Comment',
                'body' => '@'.$username.' commented on your post',
                'account_id' => (string) $actor->id,
                'status_id' => (string) $comment->id,
                'parent_status_id' => (string) $parent->id,
            ];
            if ($parent->scope === 'public' && $comment->scope === 'public'
                && ! $parent->uri && preg_match('/\A[A-Za-z0-9_]+\z/D', $recipient->username ?? '')) {
                $payload['url'] = '/p/'.$recipient->username.'/'.$parent->id;
            }
            $expiresAt = time() + $ttl;
            foreach ($user->pushSubscriptions()->select('id')->lazyById() as $subscription) {
                $job = (new DeliverWebPush((string) $user->id, (string) $subscription->id, $payload, $expiresAt))->afterCommit();
                $key = 'webpush:comment:'.$user->id.':'.$comment->id.':'.$subscription->id;
                $claim = $store->lock($key, self::DEDUPE_SECONDS);
                if (! $claim->get()) {
                    continue;
                }
                $handedToDispatcher = false;
                try {
                    $dispatcher = app(Dispatcher::class);
                    $handedToDispatcher = true;
                    $dispatcher->dispatch($job);
                    // Keep the claim until expiry. Claim-then-dispatch has a small
                    // crash window; strict exactly-once requires a durable outbox.
                } catch (Throwable) {
                    // Only release our own claim if enqueueing could not have begun.
                    // A queue exception may follow acceptance by Redis; releasing
                    // in that ambiguous case could cause duplicate deliveries.
                    if (! $handedToDispatcher) {
                        $claim->release();
                    }
                    self::report('enqueue_failed');
                }
            }
        } catch (Throwable) {
            self::report('orchestration_failed');
        }
    }

    private static function report(string $category): void
    {
        try {
            Log::warning('Web Push comment: '.$category);
        } catch (Throwable) {
            // Even a broken logger must not disrupt internal notifications.
        }
    }
}

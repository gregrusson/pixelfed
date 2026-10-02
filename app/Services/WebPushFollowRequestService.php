<?php

namespace App\Services;

use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Models\Follower;
use App\Models\FollowRequest;
use App\Models\Profile;
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

class WebPushFollowRequestService
{
    public const DEDUPE_SECONDS = 604800;

    public static function notify(FollowRequest $request): void
    {
        // Capture scalar identity before deferral; never follow a replacement row
        // or trust a caller's model attributes after the transaction commits.
        $id = (string) $request->id;
        $actorId = (string) $request->follower_id;
        $recipientId = (string) $request->following_id;
        $connection = $request->getConnectionName();
        try {
            if ($request->getConnection()->transactionLevel() > 0) {
                $request->getConnection()->afterCommit(fn () => self::notifyIdentity($id, $actorId, $recipientId, $connection));

                return;
            }
            self::notifyIdentity($id, $actorId, $recipientId, $connection);
        } catch (Throwable) {
            self::report('orchestration_failed');
        }
    }

    private static function notifyIdentity(string $id, string $actorId, string $recipientId, ?string $connection): void
    {
        try {
            $request = FollowRequest::on($connection)->find($id);
            if (! $request || (string) $request->follower_id !== $actorId
                || (string) $request->following_id !== $recipientId
                || (bool) $request->is_rejected || $request->handled_at !== null) {
                return;
            }
            $actor = Profile::find($actorId);
            $recipient = Profile::find($recipientId);
            if (! $actor || ! $recipient || $actorId === $recipientId
                || $recipient->domain !== null || ! (bool) $recipient->is_private || ! $recipient->user_id) {
                return;
            }
            $user = User::find($recipient->user_id);
            if (! $user || (string) $user->profile_id !== $recipientId
                || ! (bool) $user->notify_follow || ! $user->pushSubscriptions()->exists()
                || Follower::whereProfileId($actorId)->whereFollowingId($recipientId)->exists()) {
                return;
            }
            if (UserFilter::whereUserId($recipientId)->whereFilterableType(Profile::class)
                ->whereFilterableId($actorId)->whereIn('filter_type', ['mute', 'block'])->exists()) {
                return;
            }
            // Both policies use local SQL/cache only. Never fetch an actor or
            // consult the mobile gateway here; failed policy reads fail closed.
            if ($actor->domain !== null && (AccountService::blocksDomain($recipientId, $actor->domain)
                || in_array($actor->domain, InstanceService::getBannedDomains(), true))) {
                return;
            }
            $username = EventSupport::normalizeActorUsername($actor);
            if ($username === null) {
                return;
            }
            app(DeliveryService::class)->validateConfiguration();
            [$queueConnection, , $ttl] = DeliveryQueueConfiguration::validated();
            $dispatcher = app(Dispatcher::class);
            app(QueueFactory::class)->connection($queueConnection);
            $store = Cache::store('redis')->getStore();
            if (! $store instanceof RedisStore) {
                self::report('redis_dedupe_required');

                return;
            }
            $payload = [
                'notification_type' => 'follow_request',
                'title' => 'Follow Request',
                'body' => '@'.$username.' requested to follow you',
                'account_id' => (string) $actor->id,
                'url' => '/account/follow-requests',
            ];
            $expiresAt = time() + $ttl;
            foreach ($user->pushSubscriptions()->select('id')->lazyById() as $subscription) {
                $job = (new DeliverWebPush((string) $user->id, (string) $subscription->id, $payload, $expiresAt))->afterCommit();
                // Rows are persisted generations. The schema has no pair unique
                // constraint: distinct concurrent duplicate rows may each notify.
                $claim = $store->lock('webpush:follow_request:'.$user->id.':'.$id.':'.$subscription->id, self::DEDUPE_SECONDS);
                if (! $claim->get()) {
                    continue;
                }
                try {
                    $dispatcher->dispatch($job);
                } catch (Throwable) {
                    // Acceptance by Redis can be uncertain; retain the claim.
                    self::report('enqueue_failed');
                }
            }
            // Cancellation after enqueue cannot recall delivery. Remote rejection
            // is indistinguishable from pending until its pipeline deletes the row.
        } catch (Throwable) {
            self::report('orchestration_failed');
        }
    }

    private static function report(string $category): void
    {
        try {
            Log::warning('Web Push follow_request: '.$category);
        } catch (Throwable) {
            // Browser orchestration must never interrupt request persistence.
        }
    }
}

<?php

namespace App\Services;

use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Jobs\PushNotificationPipeline\NewPostWebPushFanout;
use App\Models\CustomFilter;
use App\Models\Follower;
use App\Models\Profile;
use App\Models\Status;
use App\Models\User;
use App\Models\UserFilter;
use App\Services\WebPush\DeliveryQueueConfiguration;
use App\Services\WebPush\DeliveryService;
use App\Services\WebPush\EventSupport;
use App\Util\Lexer\Autolink;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebPushFollowedPostService
{
    public const TYPES = ['photo', 'photo:album', 'video', 'video:album', 'photo:video:album'];

    public const DEDUPE_SECONDS = 604800;

    // Fixed from publication acceptance, including media waiting and pagination.
    // Provider delivery still has at most the existing five-minute TTL.
    public const EVENT_SECONDS = 900;

    public static function eligibleStatus(?Status $status, string $authorId): bool
    {
        return $status && ! $status->trashed()
            && (string) $status->profile_id === $authorId
            && in_array($status->type, self::TYPES, true)
            && $status->in_reply_to_id === null && $status->in_reply_to_profile_id === null
            && $status->reblog_of_id === null && $status->group_id === null
            && in_array($status->scope, ['public', 'unlisted', 'private'], true)
            && $status->visibility === $status->scope;
    }

    /** Only the trusted inbound Create handler calls this; generic fetches never do. */
    public static function remoteCreated(Status $status): void
    {
        if (! $status->wasRecentlyCreated) {
            return;
        }
        $sid = (string) $status->id;
        $aid = (string) $status->profile_id;
        $connection = $status->getConnectionName();
        $deadline = time() + self::EVENT_SECONDS;
        $callback = function () use ($sid, $aid, $connection, $deadline) {
            try {
                $status = Status::on($connection)->find($sid);
                $author = Profile::find($aid);
                if (! self::eligibleStatus($status, $aid) || ! $author || $author->domain === null
                    || $status->local || ! $status->created_at
                    || $status->created_at->lt(now()->subDay())
                    || $status->created_at->gt(now()->addMinutes(5))) {
                    return;
                }
                NewPostWebPushFanout::dispatch($sid, $aid, min($deadline, $status->created_at->getTimestamp() + 86400))->afterCommit();
            } catch (Throwable) {
                self::report('remote_orchestration_failed');
            }
        };
        self::afterCommit($status, $callback);
    }

    public static function afterCommit(Status $status, callable $callback): void
    {
        try {
            if ($status->getConnection()->transactionLevel() > 0) {
                $status->getConnection()->afterCommit($callback);
            } else {
                $callback();
            }
        } catch (Throwable) {
            self::report('orchestration_failed');
        }
    }

    /** SQL/cache only: also used immediately before provider delivery. */
    public static function eligibleRecipient(array $event, string $userId): ?array
    {
        try {
            foreach (['status_id', 'author_id', 'recipient_id', 'follower_id', 'deadline'] as $key) {
                if (! isset($event[$key]) || ! preg_match('/\A[0-9]+\z/D', (string) $event[$key])) {
                    return null;
                }
            }
            if ((int) $event['deadline'] <= time()) {
                return null;
            }
            $status = Status::find($event['status_id']);
            $aid = (string) $event['author_id'];
            $rid = (string) $event['recipient_id'];
            $author = Profile::find($aid);
            $recipient = Profile::find($rid);
            $user = User::find($userId);
            $follow = Follower::find($event['follower_id']);
            if (! self::eligibleStatus($status, $aid) || ! $author || $author->status !== null
                || ! $recipient || $recipient->status !== null || $recipient->domain !== null
                || ! $user || $user->status !== null || (string) $recipient->user_id !== $userId
                || (string) $user->profile_id !== $rid || $rid === $aid
                || ! $follow || (string) $follow->profile_id !== $rid
                || (string) $follow->following_id !== $aid || ! $follow->notify) {
                return null;
            }
            // The freshly loaded accepted relationship is the private authorization,
            // avoiding stale FollowerService caches after bulk unfollow/delete.
            if (! in_array($status->scope, ['public', 'unlisted', 'private'], true)) {
                return null;
            }
            if (UserFilter::whereUserId($rid)->whereFilterableType(Profile::class)
                ->whereFilterableId($aid)->whereIn('filter_type', ['mute', 'block'])->exists()
                || UserFilter::whereUserId($aid)->whereFilterableType(Profile::class)
                    ->whereFilterableId($rid)->whereFilterType('block')->exists()) {
                return null;
            }
            if ($author->domain !== null && (AccountService::blocksDomain($rid, $author->domain)
                || in_array($author->domain, InstanceService::getBannedDomains(), true))) {
                return null;
            }
            $filters = array_filter(CustomFilter::getCachedFiltersForAccount($rid), fn ($item) => count(array_intersect(['home', 'notifications'], $item[0]->context ?? [])) > 0);
            $content = $status->local || ! $status->rendered
                ? ($status->caption ? nl2br(Autolink::create()->autolink($status->caption)) : '')
                : $status->rendered;
            foreach (CustomFilter::applyCachedFilters($filters, ['content' => $content]) as $match) {
                if ($match['filter']['filter_action'] === 'hide') {
                    return null;
                }
            }
            $username = EventSupport::normalizeActorUsername($author);

            return $username === null ? null : [$user, $username];
        } catch (Throwable) {
            self::report('eligibility_failed');

            return null;
        }
    }

    public static function notify(array $event, string $userId): void
    {
        $status = Status::find($event['status_id']);
        if (! $status) {
            return;
        }
        self::afterCommit($status, function () use ($event, $userId) {
            try {
                $eligible = self::eligibleRecipient($event, $userId);
                if (! $eligible) {
                    return;
                }
                [$user, $username] = $eligible;
                if (! $user->pushSubscriptions()->exists()) {
                    return;
                }
                app(DeliveryService::class)->validateConfiguration();
                [$connection, $queue, $ttl] = DeliveryQueueConfiguration::validated();
                if ($connection !== 'redis' || $queue !== 'pushnotify') {
                    return;
                }
                $store = Cache::store('redis')->getStore();
                if (! $store instanceof RedisStore) {
                    return;
                }
                $payload = [
                    'notification_type' => 'new_post', 'title' => 'New Post',
                    'body' => '@'.$username.' posted something new',
                    'account_id' => (string) $event['author_id'],
                    'status_id' => (string) $event['status_id'],
                    'url' => '/i/web/post/'.$event['status_id'],
                ];
                $expires = min((int) $event['deadline'], time() + $ttl);
                foreach ($user->pushSubscriptions()->select('id')->lazyById() as $subscription) {
                    $claim = $store->lock('webpush:new_post:'.$userId.':'.$event['status_id'].':'.$subscription->id, self::DEDUPE_SECONDS);
                    if (! $claim->get()) {
                        continue;
                    }
                    try {
                        DeliverWebPush::dispatch($userId, (string) $subscription->id, $payload, $expires, $event)->afterCommit();
                    } catch (Throwable) {
                        // Acceptance can be uncertain. Keep the claim, as other events do.
                        self::report('enqueue_failed');
                    }
                }
            } catch (Throwable) {
                self::report('orchestration_failed');
            }
        });
    }

    public static function report(string $category): void
    {
        try {
            Log::warning('Web Push new_post: '.$category);
        } catch (Throwable) {
            // Browser notifications must not interrupt publication.
        }
    }
}

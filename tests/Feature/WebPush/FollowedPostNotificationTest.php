<?php

use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Models\CustomFilter;
use App\Models\CustomFilterKeyword;
use App\Models\Follower;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use App\Services\InstanceService;
use App\Services\WebPush\DeliveryService;
use App\Services\WebPushFollowedPostService;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/WebPushFollowedPostFixture.php';
beforeEach(fn () => setupWebPushFollowedPostFixture($this));
afterEach(fn () => tearDownWebPushCommentFixture());

it('accepts one minimal payload per owned subscription and keeps browser metadata private', function () {
    User::find(1)->pushSubscriptions()->create(['endpoint' => 'https://push.example.com/second']);
    User::find(3)->pushSubscriptions()->create(['endpoint' => 'https://push.example.com/unrelated']);
    WebPushFollowedPostService::notify($this->event, '1');
    WebPushFollowedPostService::notify($this->event, '1');
    Queue::assertPushed(DeliverWebPush::class, 2);
    Queue::assertPushed(DeliverWebPush::class, function ($job) {
        expect($job->payload)->toBe(['notification_type' => 'new_post', 'title' => 'New Post', 'body' => '@bob posted something new', 'account_id' => '20', 'status_id' => '300', 'url' => '/i/web/post/300'])
            ->and($job->newPostEvent)->toBe($this->event)->and($job->connection)->toBe('redis')->and($job->queue)->toBe('pushnotify')
            ->and(serialize($job))->not->toContain('secret caption', 'secret-public-key', 'secret-auth', 'secret-endpoint');

        return true;
    });
    expect(Notification::count())->toBe(0)->and(count($this->claims))->toBe(2);
    Queue::assertCount(2);
});

function invalidateFollowedPost($test, string $change): void
{
    match ($change) {
        'unfollow' => DB::table('followers')->where('id', $test->follow->id)->delete(),
        'notify_off' => DB::table('followers')->where('id', $test->follow->id)->update(['notify' => false]),
        'replaced' => (function () use ($test) {
            DB::table('followers')->where('id', $test->follow->id)->delete();
            Follower::withoutEvents(fn () => Follower::create(['profile_id' => 10, 'following_id' => 20, 'notify' => true]));
        })(),
        'mute', 'block' => DB::table('user_filters')->insert(['user_id' => 10, 'filterable_id' => 20, 'filterable_type' => Profile::class, 'filter_type' => $change]),
        'author_block' => DB::table('user_filters')->insert(['user_id' => 20, 'filterable_id' => 10, 'filterable_type' => Profile::class, 'filter_type' => 'block']),
        'deleted' => DB::table('statuses')->where('id', 300)->update(['deleted_at' => now()]),
        'direct', 'draft', 'archived', 'inconsistent' => DB::table('statuses')->where('id', 300)->update(['scope' => $change === 'inconsistent' ? 'private' : $change]),
        'reply' => DB::table('statuses')->where('id', 300)->update(['in_reply_to_id' => 100]),
        'reply_profile' => DB::table('statuses')->where('id', 300)->update(['in_reply_to_profile_id' => 10]),
        'share' => DB::table('statuses')->where('id', 300)->update(['reblog_of_id' => 100]),
        'group' => DB::table('statuses')->where('id', 300)->update(['group_id' => 1]),
        'text', 'poll', 'story' => DB::table('statuses')->where('id', 300)->update(['type' => $change]),
        'expired' => $test->event['deadline'] = time() - 1,
        'inactive_user' => DB::table('users')->where('id', 1)->update(['status' => 'disabled']),
        'inactive_author' => DB::table('profiles')->where('id', 20)->update(['status' => 'suspended']),
        'ownership' => DB::table('users')->where('id', 1)->update(['profile_id' => 30]),
    };
}

it('suppresses ineligible posts and relationships before claims', function (string $change) {
    invalidateFollowedPost($this, $change);
    WebPushFollowedPostService::notify($this->event, '1');
    Queue::assertNothingPushed();
    expect($this->claims)->toBeEmpty();
})->with(['unfollow', 'notify_off', 'replaced', 'mute', 'block', 'author_block', 'deleted', 'direct', 'draft', 'archived', 'inconsistent', 'reply', 'reply_profile', 'share', 'group', 'text', 'poll', 'story', 'expired', 'inactive_user', 'inactive_author', 'ownership']);

it('rechecks policy after delivery enqueue without provider requests', function (string $change) {
    WebPushFollowedPostService::notify($this->event, '1');
    $job = Queue::pushed(DeliverWebPush::class)->first();
    invalidateFollowedPost($this, $change);
    if ($change === 'expired') {
        $job = new DeliverWebPush($job->userId, $job->subscriptionId, $job->payload, time() + 180, $this->event);
    }
    $job->setJob(Mockery::mock(RedisJob::class));
    $job->handle($this->delivery); // fixture forbids send and DNS
})->with(['unfollow', 'notify_off', 'replaced', 'mute', 'block', 'author_block', 'deleted', 'direct', 'expired']);

it('allows authorized visibility and edits without creating another generation', function (string $scope) {
    DB::table('statuses')->where('id', 300)->update(['scope' => $scope, 'visibility' => $scope, 'edited_at' => now()]);
    WebPushFollowedPostService::notify($this->event, '1');
    Queue::assertPushed(DeliverWebPush::class, 1);
})->with(['public', 'unlisted', 'private']);

it('reuses actual CustomFilter matching and suppresses only hide', function (int $action) {
    $filter = CustomFilter::create(['profile_id' => 10, 'phrase' => 'hide caption', 'context' => ['home'], 'action' => $action]);
    CustomFilterKeyword::create(['custom_filter_id' => $filter->id, 'keyword' => 'secret', 'whole_word' => true]);
    Cache::forget('filters:v3:10');
    WebPushFollowedPostService::notify($this->event, '1');
    Queue::assertPushed(DeliverWebPush::class, $action === 1 ? 0 : 1);
})->with([0, 1, 2]);

it('normalizes remote actors and observes current domain policies', function (string $policy) {
    DB::table('profiles')->where('id', 20)->update(['domain' => 'remote.example', 'username' => '@bob@remote.example', 'user_id' => null]);
    if ($policy === 'recipient') {
        DB::table('user_domain_blocks')->insert(['profile_id' => 10, 'domain' => 'remote.example']);
    }
    if ($policy === 'instance') {
        DB::table('instances')->insert(['domain' => 'remote.example', 'banned' => true]);
    }
    WebPushFollowedPostService::notify($this->event, '1');
    Queue::assertPushed(DeliverWebPush::class, $policy === 'allow' ? 1 : 0);
    if ($policy === 'allow') {
        Queue::assertPushed(DeliverWebPush::class, fn ($job) => $job->payload['body'] === '@bob@remote.example posted something new');
    }
})->with(['allow', 'recipient', 'instance']);

it('allows an eligible queued new-post delivery through the existing provider boundary', function () {
    WebPushFollowedPostService::notify($this->event, '1');
    $job = Queue::pushed(DeliverWebPush::class)->sole();
    $job->setJob(Mockery::mock(RedisJob::class));
    $provider = Mockery::mock(DeliveryService::class);
    $provider->shouldReceive('send')->once()->withArgs(fn ($subscription, $payload, $ttl) => (string) $subscription->id === (string) $this->subscription->id && $payload === $job->payload && $ttl > 0 && $ttl <= 300)->andReturn('delivered');
    $job->handle($provider); // Provider itself is mocked: no network/live push.
});

it('requires server-side metadata and current subscription ownership at final delivery', function (string $change) {
    WebPushFollowedPostService::notify($this->event, '1');
    $job = Queue::pushed(DeliverWebPush::class)->sole();
    if ($change === 'metadata') {
        $job = new DeliverWebPush($job->userId, $job->subscriptionId, $job->payload, $job->expiresAt);
    }
    if ($change === 'owner') {
        DB::table('push_subscriptions')->where('id', $job->subscriptionId)->update(['subscribable_id' => 3]);
    }
    if ($change === 'deleted') {
        DB::table('push_subscriptions')->delete();
    }
    $job->setJob(Mockery::mock(RedisJob::class));
    $job->handle($this->delivery);
})->with(['metadata', 'owner', 'deleted']);

it('rechecks remote domain policy immediately before provider delivery', function (string $policy) {
    makeWebPushRequestActorRemote();
    WebPushFollowedPostService::notify($this->event, '1');
    $job = Queue::pushed(DeliverWebPush::class)->sole();
    if ($policy === 'recipient') {
        DB::table('user_domain_blocks')->insert(['profile_id' => 10, 'domain' => 'remote.example']);
    } else {
        DB::table('instances')->insert(['domain' => 'remote.example', 'banned' => true]);
        Cache::forget(InstanceService::CACHE_KEY_BANNED_DOMAINS);
    }
    $job->setJob(Mockery::mock(RedisJob::class));
    $job->handle($this->delivery);
})->with(['recipient', 'instance']);

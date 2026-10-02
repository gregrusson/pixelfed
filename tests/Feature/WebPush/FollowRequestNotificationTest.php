<?php

use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Models\FollowRequest;
use App\Models\Notification;
use App\Models\User;
use App\Services\WebPushFollowRequestService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/WebPushFollowRequestFixture.php';

beforeEach(function () {
    setupWebPushFollowRequestFixture($this);
    Queue::fake();
});
afterEach(fn () => tearDownWebPushCommentFixture());

it('notifies via the registered created observer with only the reviewed payload', function () {
    $request = createPendingWebPushRequest(['activity' => ['actor' => 'https://evil.example/private-content']]);
    Queue::assertPushed(DeliverWebPush::class, function ($job) {
        expect($job->payload)->toBe([
            'notification_type' => 'follow_request', 'title' => 'Follow Request',
            'body' => '@bob requested to follow you', 'account_id' => '20', 'url' => '/account/follow-requests',
        ])->and($job->userId)->toBe('1')->and($job->subscriptionId)->toBe((string) $this->subscription->id)
            ->and($job->afterCommit)->toBeTrue()->and($job->connection)->toBe('redis')->and($job->queue)->toBe('pushnotify')
            ->and($job->expiresAt)->toBeGreaterThanOrEqual(time() + 179)
            ->and(serialize($job))->not->toContain('evil.example', 'secret-endpoint', 'secret-public-key', 'secret-auth');

        return true;
    });
    expect(array_keys($this->claims))->toBe(['test:webpush:follow_request:1:'.$request->id.':'.$this->subscription->id])
        ->and(Notification::count())->toBe(0)->and((bool) $request->fresh()->is_local)->toBeFalse();
    Queue::assertCount(1); // No mobile or other jobs.
});

it('suppresses stale or ineligible requests before configuration and claims', function (string $change) {
    $request = FollowRequest::withoutEvents(fn () => createPendingWebPushRequest());
    changeWebPushRequestEligibility($change, $request);
    $this->delivery->shouldNotReceive('validateConfiguration');
    WebPushFollowRequestService::notify($request);
    Queue::assertNothingPushed();
    expect($this->claims)->toBeEmpty();
})->with([
    'missing_request', 'actor_identity', 'recipient_identity', 'missing_actor', 'deleted_actor',
    'missing_recipient', 'deleted_recipient', 'remote_recipient', 'public_recipient', 'no_user_id',
    'missing_user', 'deleted_user', 'user_mismatch', 'rejected', 'handled', 'following',
    'preference', 'subscription', 'mute', 'block', 'username',
]);

it('rejects fabricated and self request events', function () {
    WebPushFollowRequestService::notify(new FollowRequest(['id' => 999, 'follower_id' => 20, 'following_id' => 10]));
    createPendingWebPushRequest(['follower_id' => 10]);
    Queue::assertNothingPushed();
    expect($this->claims)->toBeEmpty();
});

it('uses current local domain policies for remote actors including a cold banned-domain cache', function (string $policy) {
    makeWebPushRequestActorRemote();
    if ($policy === 'recipient') {
        DB::table('user_domain_blocks')->insert(['profile_id' => 10, 'domain' => 'remote.example']);
    } else {
        DB::table('instances')->insert(['domain' => 'remote.example', 'banned' => true]);
    }
    createPendingWebPushRequest();
    Queue::assertNothingPushed();
    expect($this->claims)->toBeEmpty();
})->with(['recipient', 'instance']);

it('fails closed when a required domain policy cannot be read', function () {
    makeWebPushRequestActorRemote();
    DB::statement('DROP TABLE user_domain_blocks');
    createPendingWebPushRequest();
    Queue::assertNothingPushed();
    expect($this->claims)->toBeEmpty();
});

it('preserves remote username normalization and excludes arbitrary activity text', function (string $username, string $display) {
    makeWebPushRequestActorRemote();
    DB::table('profiles')->where('id', 20)->update(['username' => $username]);
    createPendingWebPushRequest(['activity' => ['id' => 'https://remote.example/secret', 'actor' => '<script>secret</script>']]);
    Queue::assertPushed(DeliverWebPush::class, fn ($job) => $job->payload['body'] === $display.' requested to follow you');
    Queue::assertCount(1);
})->with([
    ['@bob@remote.example', '@bob@remote.example'],
    ['@.bob@remote.example', '@.bob@remote.example'],
    ['@-bob@remote.example', '@-bob@remote.example'],
]);

it('rejects unsafe or oversized actor identifiers', function (string $username, bool $remote) {
    if ($remote) {
        makeWebPushRequestActorRemote();
    }
    DB::table('profiles')->where('id', 20)->update(['username' => $username]);
    createPendingWebPushRequest();
    Queue::assertNothingPushed();
    expect($this->claims)->toBeEmpty();
})->with([
    ['@@bob@remote.example', true], ['@...@remote.example', true], ['@bob/remote.example', true],
    [str_repeat('a', 256), false], ['@'.str_repeat('a', 256), true], ['', false], ['bob\n', false],
]);

it('dedupes by generation per owned subscription and does not notify on updates', function () {
    $second = User::find(1)->pushSubscriptions()->create(['endpoint' => 'https://push.example.com/second']);
    User::find(2)->pushSubscriptions()->create(['endpoint' => 'https://push.example.com/unrelated']);
    $request = createPendingWebPushRequest();
    WebPushFollowRequestService::notify($request);
    $request->update(['activity' => ['id' => 'https://remote.example/changed']]);
    Queue::assertPushed(DeliverWebPush::class, 2);
    expect($this->claims)->toHaveCount(2);
    foreach (Queue::pushed(DeliverWebPush::class) as $job) {
        expect($job->userId)->toBe('1')->and($job->subscriptionId)->toBeIn([(string) $second->id, (string) $this->subscription->id]);
    }
});

it('permits a new row generation immediately after deletion and suppresses the old row', function () {
    $old = createPendingWebPushRequest();
    $old->delete();
    $new = createPendingWebPushRequest();
    WebPushFollowRequestService::notify($old);
    expect($new->id)->not->toBe($old->id)->and($this->claims)->toHaveCount(2);
    Queue::assertPushed(DeliverWebPush::class, 2);
    expect(array_keys($this->claims))->toBe([
        'test:webpush:follow_request:1:'.$old->id.':'.$this->subscription->id,
        'test:webpush:follow_request:1:'.$new->id.':'.$this->subscription->id,
    ]);
});

it('treats genuinely distinct duplicate rows as separate persisted generations', function () {
    createPendingWebPushRequest();
    createPendingWebPushRequest();
    expect(FollowRequest::count())->toBe(2)->and($this->claims)->toHaveCount(2);
    Queue::assertPushed(DeliverWebPush::class, 2);
});

it('requires a RedisStore before claiming', function () {
    Cache::extend('redis', fn () => Cache::store('array'));
    Cache::forgetDriver('redis');
    createPendingWebPushRequest();
    expect($this->claims)->toBeEmpty();
    Queue::assertNothingPushed();
});

it('keeps a generation claim when dispatch acceptance is uncertain', function () {
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('uncertain acceptance'));
    app()->instance(Dispatcher::class, $dispatcher);
    $request = createPendingWebPushRequest();
    WebPushFollowRequestService::notify($request);
    expect($this->claims)->toHaveCount(1);
    Queue::assertNothingPushed();
});

it('fails closed without claims when local delivery preparation fails', function () {
    $this->delivery->shouldReceive('validateConfiguration')->once()->andThrow(new RuntimeException('invalid configuration'));
    createPendingWebPushRequest();
    expect($this->claims)->toBeEmpty();
    Queue::assertNothingPushed();
});

it('never performs HTTP DNS or delivery during successful orchestration', function () {
    // The fixture forbids DNS resolution and DeliveryService::send(), and
    // rejects stray HTTP. A successful queued job proves those failures were
    // not swallowed by the service's fail-closed exception handling.
    createPendingWebPushRequest();
    Http::assertNothingSent();
    Queue::assertPushed(DeliverWebPush::class, 1);
    expect($this->claims)->toHaveCount(1);
});

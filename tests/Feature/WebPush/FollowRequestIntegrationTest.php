<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Api\ApiV1Controller;
use App\Jobs\FollowPipeline\FollowAcceptPipeline;
use App\Jobs\FollowPipeline\FollowPipeline;
use App\Jobs\FollowPipeline\FollowRejectPipeline;
use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Models\Follower;
use App\Models\FollowRequest;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use App\Services\AccountService;
use App\Services\RelationshipService;
use App\Services\WebPushFollowRequestService;
use App\Util\ActivityPub\Inbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Laravel\Passport\AccessToken;

require_once __DIR__.'/../../Support/WebPushFollowRequestFixture.php';

class WebPushFollowRequestTestInbox extends Inbox
{
    // Isolate remote fetching/signatures; preserve real validation, Follow/Undo
    // handlers, request persistence, observer, domain policies and orchestration.
    public function validateAndFetchActor(string $actorUrl): ?Profile
    {
        return Profile::whereRemoteUrl($actorUrl)->first();
    }
}

function inboundPendingWebPushFollow(string $type = 'Follow'): WebPushFollowRequestTestInbox
{
    $follow = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => 'https://remote.example/activities/follow-1', 'type' => 'Follow',
        'actor' => 'https://remote.example/users/bob', 'object' => 'https://pixelfed.example/users/alice',
    ];
    $payload = $type === 'Follow' ? $follow : [
        'id' => 'https://remote.example/activities/undo-1', 'type' => 'Undo',
        'actor' => $follow['actor'], 'object' => $follow,
    ];

    return new WebPushFollowRequestTestInbox([], null, $payload);
}

function pendingFollowApiRequest(string $url, int $userId = 2): Request
{
    $user = User::find($userId)->withAccessToken(new AccessToken(['oauth_scopes' => ['follow']]));
    $request = Request::create($url, 'POST');
    $request->setUserResolver(fn () => $user);

    return $request;
}

beforeEach(function () {
    setupWebPushFollowRequestFixture($this);
    Queue::fake();
    config(['exp.emc' => false]);
    Cache::put('user:last_active_at:id:2', true);
    Redis::shouldReceive('zadd', 'del', 'expire', 'srem')->andReturn(1);
    Redis::shouldReceive('zrevrange')->andReturn([]);
    // Isolate account/relationship rendering, which invalidations would rebuild
    // against unrelated schema. Persistence and push policy remain real.
    $realCache = Cache::getFacadeRoot();
    $cache = Mockery::mock($realCache);
    $cache->shouldReceive('remember')->andReturnUsing(function ($key, $ttl, $callback) use ($realCache) {
        if (str_starts_with($key, AccountService::CACHE_KEY)) {
            return ['id' => '20', 'acct' => 'bob', 'url' => 'https://pixelfed.example/bob'];
        }
        if (str_starts_with($key, RelationshipService::CACHE_KEY)) {
            return ['id' => '10'];
        }

        return $realCache->remember($key, $ttl, $callback);
    });
    Cache::swap($cache);
});
afterEach(fn () => tearDownWebPushCommentFixture());

it('creates one pending generation through the actual local API controller', function () {
    $request = pendingFollowApiRequest('/api/v1/accounts/10/follow');
    (new ApiV1Controller)->accountFollowById($request, '10');
    $first = FollowRequest::sole();
    (new ApiV1Controller)->accountFollowById($request, '10');
    expect(FollowRequest::count())->toBe(1)->and(FollowRequest::sole()->id)->toBe($first->id)
        ->and(Notification::count())->toBe(0)->and(Follower::count())->toBe(0)
        ->and(array_keys($this->claims))->toBe(['test:webpush:follow_request:1:'.$first->id.':'.$this->subscription->id]);
    Queue::assertPushed(DeliverWebPush::class, 1);
    Queue::assertCount(1);
    Queue::assertPushed(DeliverWebPush::class, fn ($job) => $job->payload === [
        'notification_type' => 'follow_request', 'title' => 'Follow Request',
        'body' => '@bob requested to follow you', 'account_id' => '20', 'url' => '/account/follow-requests',
    ]);
});

it('cancels a local request through the real API and permits a new generation', function () {
    $controller = new ApiV1Controller;
    $controller->accountFollowById(pendingFollowApiRequest('/api/v1/accounts/10/follow'), '10');
    $old = FollowRequest::sole();
    $controller->accountUnfollowById(pendingFollowApiRequest('/api/v1/accounts/10/unfollow'), '10');
    expect(FollowRequest::count())->toBe(0);
    WebPushFollowRequestService::notify($old);
    Queue::assertPushed(DeliverWebPush::class, 1);
    $controller->accountFollowById(pendingFollowApiRequest('/api/v1/accounts/10/follow'), '10');
    expect(FollowRequest::sole()->id)->not->toBe($old->id)->and($this->claims)->toHaveCount(2);
    Queue::assertPushed(DeliverWebPush::class, 2);
});

it('persists a remote Follow and handles replay without internal or mobile notifications', function () {
    makeWebPushRequestActorRemote();
    inboundPendingWebPushFollow()->handle();
    inboundPendingWebPushFollow()->handle();
    expect(FollowRequest::count())->toBe(1)->and(FollowRequest::sole()->activity)->toBe([
        'id' => 'https://remote.example/activities/follow-1', 'type' => 'Follow',
        'actor' => 'https://remote.example/users/bob', 'object' => 'https://pixelfed.example/users/alice',
    ])->and(Notification::count())->toBe(0)->and($this->claims)->toHaveCount(1);
    Queue::assertPushed(DeliverWebPush::class, function ($job) {
        expect($job->payload)->toBe([
            'notification_type' => 'follow_request', 'title' => 'Follow Request',
            'body' => '@bob@remote.example requested to follow you', 'account_id' => '20', 'url' => '/account/follow-requests',
        ])->and(serialize($job))->not->toContain('https://remote.example', 'activities/follow-1');

        return true;
    });
    Queue::assertCount(1);
});

it('hard-deletes a pending remote request through Undo and allows re-request', function () {
    makeWebPushRequestActorRemote();
    inboundPendingWebPushFollow()->handle();
    $old = FollowRequest::sole();
    inboundPendingWebPushFollow('Undo')->handle();
    expect(FollowRequest::count())->toBe(0);
    WebPushFollowRequestService::notify($old);
    inboundPendingWebPushFollow()->handle();
    expect(FollowRequest::sole()->id)->not->toBe($old->id)->and($this->claims)->toHaveCount(2);
    Queue::assertPushed(DeliverWebPush::class, 2);
});

it('preserves distinct local completed-follow push and remote acceptance asymmetry', function (bool $remote) {
    if ($remote) {
        makeWebPushRequestActorRemote();
    }
    $pending = createPendingWebPushRequest();
    Follower::withoutEvents(fn () => (new ApiV1Controller)->accountFollowRequestAccept(
        pendingFollowApiRequest('/api/v1/follow_requests/20/authorize', 1), '20'
    ));
    expect(Follower::count())->toBe(1);
    WebPushFollowRequestService::notify($pending);
    if ($remote) {
        Queue::assertPushed(FollowAcceptPipeline::class, 1);
        Queue::assertNotPushed(FollowPipeline::class);
        expect(Notification::count())->toBe(0)->and($pending->fresh())->not->toBeNull();
        foreach (Queue::pushed(FollowAcceptPipeline::class) as $job) {
            $job->handle(); // Signed delivery is disabled in the testing environment.
        }
        expect($pending->fresh())->toBeNull();
        Queue::assertPushed(DeliverWebPush::class, 1);
    } else {
        foreach (Queue::pushed(FollowPipeline::class) as $job) {
            $job->handle();
        }
        expect($pending->fresh())->toBeNull()->and(Notification::sole()->action)->toBe('follow');
        Queue::assertPushed(DeliverWebPush::class, 2);
        expect(Queue::pushed(DeliverWebPush::class)->map(fn ($job) => $job->payload['notification_type'])->all())
            ->toBe(['follow_request', 'follow']);
    }
})->with([false, true]);

it('permits re-request after actual local or remote reject deletion completes', function (bool $remote, bool $web) {
    if ($remote) {
        makeWebPushRequestActorRemote();
    }
    $old = createPendingWebPushRequest();
    $request = pendingFollowApiRequest('/api/v1/follow_requests/20/reject', 1);
    if ($web) {
        $request->merge(['action' => 'reject', 'id' => $old->id]);
        (new AccountController)->followRequestHandle($request);
    } else {
        (new ApiV1Controller)->accountFollowRequestReject($request, '20');
    }
    if ($remote) {
        expect($old->fresh())->not->toBeNull()->and((bool) $old->fresh()->is_rejected)->toBeFalse()
            ->and($old->fresh()->handled_at)->toBeNull();
        // A rejection-in-progress is still the same persisted generation.
        inboundPendingWebPushFollow()->handle();
        expect(FollowRequest::sole()->id)->toBe($old->id);
        Queue::assertPushed(DeliverWebPush::class, 1);
        foreach (Queue::pushed(FollowRejectPipeline::class) as $job) {
            $job->handle(); // Real transformation/deletion, with no production transport.
        }
    }
    expect($old->fresh())->toBeNull();
    $new = createPendingWebPushRequest();
    expect($new->id)->not->toBe($old->id)->and($this->claims)->toHaveCount(2);
    Queue::assertPushed(DeliverWebPush::class, 2);
})->with([false, true])->with([false, true]);

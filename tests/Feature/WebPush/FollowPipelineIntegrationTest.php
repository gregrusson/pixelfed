<?php

use App\Http\Controllers\Api\ApiV1Controller;
use App\Jobs\FollowPipeline\FollowAcceptPipeline;
use App\Jobs\FollowPipeline\FollowPipeline;
use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Models\Follower;
use App\Models\FollowRequest;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use App\Services\AccountService;
use App\Services\InstanceService;
use App\Services\RelationshipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/WebPushFollowFixture.php';

beforeEach(function () {
    setupWebPushFollowFixture($this);
    Queue::fake();
    config(['exp.emc' => false]);
    Cache::put('user:last_active_at:id:2', true);
    Cache::put(InstanceService::CACHE_KEY_BANNED_DOMAINS, []);
    Redis::shouldReceive('zadd', 'del')->andReturn(1);
    // Isolate account/relationship rendering caches, which FollowPipeline
    // invalidates. The real pipeline, Notification, observer and push run.
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

it('creates the actual follow Notification through FollowPipeline and the observer', function (bool $remote) {
    if ($remote) {
        DB::table('profiles')->where('id', 20)->update([
            'domain' => 'remote.example', 'username' => '@bob@remote.example', 'user_id' => null,
        ]);
    }
    (new FollowPipeline($this->follower))->handle();
    (new FollowPipeline($this->follower->fresh()))->handle();
    expect(Notification::count())->toBe(2);
    $notification = Notification::first();
    expect($notification->action)->toBe('follow')
        ->and((string) $notification->actor_id)->toBe('20')
        ->and((string) $notification->profile_id)->toBe('10')
        ->and((string) $notification->item_id)->toBe('10')
        ->and($notification->item_type)->toBe(Profile::class);
    Queue::assertPushed(DeliverWebPush::class, 1);
})->with([false, true]);

it('preserves blocked follow rejection in the actual API controller', function () {
    DB::table('followers')->delete();
    DB::table('user_filters')->insert([
        'user_id' => 10, 'filterable_id' => 20, 'filterable_type' => Profile::class, 'filter_type' => 'block',
    ]);
    $user = User::find(2)->withAccessToken(new AccessToken(['oauth_scopes' => ['follow']]));
    $request = Request::create('/api/v1/accounts/10/follow', 'POST');
    $request->setUserResolver(fn () => $user);
    try {
        (new ApiV1Controller)->accountFollowById($request, '10');
        $this->fail('Blocked follow should abort');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(400)->and($e->getMessage())->toBe('You cannot follow this user.');
    }
    expect(Follower::count())->toBe(0)->and(Notification::count())->toBe(0)->and($this->claims)->toBeEmpty();
    Queue::assertNotPushed(DeliverWebPush::class);
});

it('preserves local versus remote request acceptance notification behavior', function (bool $remote) {
    DB::table('followers')->delete();
    if ($remote) {
        DB::table('profiles')->where('id', 20)->update([
            'domain' => 'remote.example', 'username' => '@bob@remote.example', 'user_id' => null,
        ]);
    }
    $pending = FollowRequest::create(['follower_id' => 20, 'following_id' => 10]);
    $user = User::find(1)->withAccessToken(new AccessToken(['oauth_scopes' => ['follow']]));
    $request = Request::create('/api/v1/follow_requests/20/authorize', 'POST');
    $request->setUserResolver(fn () => $user);
    Follower::withoutEvents(fn () => (new ApiV1Controller)->accountFollowRequestAccept($request, '20'));
    expect(Follower::whereProfileId(20)->whereFollowingId(10)->exists())->toBeTrue();
    if ($remote) {
        Queue::assertPushed(FollowAcceptPipeline::class, 1);
        Queue::assertNotPushed(FollowPipeline::class);
        expect($pending->fresh())->not->toBeNull();
        expect(Notification::count())->toBe(0)->and($this->claims)->toBeEmpty();
        Queue::assertNotPushed(DeliverWebPush::class);
    } else {
        Queue::assertPushed(FollowPipeline::class, 1);
        foreach (Queue::pushed(FollowPipeline::class) as $job) {
            $job->handle();
        }
        expect($pending->fresh())->toBeNull()->and(Notification::sole()->action)->toBe('follow');
        Queue::assertPushed(DeliverWebPush::class, 1);
    }
})->with([false, true]);

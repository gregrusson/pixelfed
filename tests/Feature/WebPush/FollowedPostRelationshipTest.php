<?php

use App\Http\Controllers\Api\ApiV1Controller;
use App\Models\Follower;
use App\Models\FollowRequest;
use App\Models\User;
use App\Services\AccountService;
use App\Services\RelationshipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/WebPushFollowedPostFixture.php';
beforeEach(function () {
    setupWebPushFollowedPostFixture($this);
    Redis::shouldReceive('del')->andReturn(1);
    $real = Cache::getFacadeRoot();
    $cache = Mockery::mock($real);
    $cache->shouldReceive('remember')->andReturnUsing(function ($key, $ttl, $callback) use ($real) {
        if (str_starts_with($key, AccountService::CACHE_KEY)) {
            $id = substr($key, strlen(AccountService::CACHE_KEY));

            return ['id' => $id, 'username' => 'bob'];
        }

        return $real->remember($key, $ttl, $callback);
    });
    Cache::swap($cache);
});
afterEach(fn () => tearDownWebPushCommentFixture());

function notifyFollowRequest(array $data, int $userId = 1): Request
{
    $user = User::find($userId)->withAccessToken(new AccessToken(['oauth_scopes' => ['follow']]));
    $request = Request::create('/api/v1/accounts/20/follow', 'POST', $data);
    $request->setUserResolver(fn () => $user);

    return $request;
}

it('exposes, persists and refreshes the authenticated accepted relationship preference', function () {
    $this->follow->update(['notify' => false]);
    expect(RelationshipService::get(10, 20)['notifying'])->toBeFalse();
    $controller = new ApiV1Controller;
    $enabled = $controller->accountFollowById(notifyFollowRequest(['notify' => 'true', 'notify_only' => true]), '20')->getData(true);
    expect($enabled['notifying'])->toBeTrue()->and($this->follow->fresh()->notify)->toBeTrue()
        ->and(RelationshipService::get(10, 20)['notifying'])->toBeTrue();
    $disabled = $controller->accountFollowById(notifyFollowRequest(['notify' => 'false', 'notify_only' => true]), '20')->getData(true);
    expect($disabled['notifying'])->toBeFalse()->and($this->follow->fresh()->notify)->toBeFalse();
});

it('does not let stale UI toggles follow an account or modify another user relationship', function () {
    $this->follow->update(['notify' => false]);
    Cache::put('user:last_active_at:id:3', true);
    try {
        (new ApiV1Controller)->accountFollowById(notifyFollowRequest(['notify' => true, 'notify_only' => true], 3), '20');
        $this->fail('Expected conflict');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }
    expect($this->follow->fresh()->notify)->toBeFalse()->and(Follower::count())->toBe(1);
});

it('keeps pending follow opt-in false and accepted re-follow defaults false', function () {
    DB::table('followers')->delete();
    DB::table('profiles')->where('id', 20)->update(['is_private' => true]);
    (new ApiV1Controller)->accountFollowById(notifyFollowRequest(['notify' => true]), '20');
    expect(FollowRequest::count())->toBe(1)->and(Follower::count())->toBe(0)
        ->and(RelationshipService::get(10, 20)['notifying'])->toBeFalse();
    DB::table('profiles')->where('id', 20)->update(['is_private' => false]);
    Follower::withoutEvents(fn () => (new ApiV1Controller)->accountFollowById(notifyFollowRequest([]), '20'));
    expect(Follower::sole()->notify)->toBeFalse();
});

it('sets explicit notify only on immediate accepted-follow creation', function (bool $notify) {
    DB::table('followers')->delete();
    DB::table('profiles')->where('id', 20)->update(['is_private' => false]);
    Follower::withoutEvents(fn () => (new ApiV1Controller)->accountFollowById(notifyFollowRequest(['notify' => $notify]), '20'));
    expect(Follower::sole()->notify)->toBe($notify)->and(RelationshipService::get(10, 20)['notifying'])->toBe($notify);
})->with([false, true]);

it('rejects malformed opt-in without altering the relationship', function (mixed $notify) {
    $this->follow->update(['notify' => false]);
    expect(fn () => (new ApiV1Controller)->accountFollowById(notifyFollowRequest(['notify' => $notify]), '20'))->toThrow(ValidationException::class);
    expect($this->follow->fresh()->notify)->toBeFalse();
})->with(['yes', 'on', '', null, [['true']]]);

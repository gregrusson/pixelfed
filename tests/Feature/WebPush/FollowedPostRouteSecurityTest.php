<?php

use App\Http\Controllers\Api\ApiV1Controller;
use App\Http\Controllers\SpaController;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('constructs a local permalink and ignores malicious cached URLs', function (string $url) {
    config(['exp.spa' => true]);
    Cache::put(StatusService::key('300', false), ['local' => true, 'visibility' => 'public', 'url' => $url, 'account' => ['username' => 'bob.name-1', 'url' => $url]]);
    $response = (new SpaController)->webPost(Request::create('/i/web/post/300'), '300');
    expect($response->getTargetUrl())->toBe(url('/p/bob.name-1/300'));
})->with(['https://evil.example/post', '//evil.example/escape', 'javascript:alert(1)']);

it('keeps remote, private and unsafe local post redirects on login', function (array $post) {
    config(['exp.spa' => true]);
    Cache::put(StatusService::key('300', false), $post);
    expect((new SpaController)->webPost(Request::create('/i/web/post/300'), '300')->getTargetUrl())->toBe(url('/login'));
})->with([
    [['local' => false, 'visibility' => 'public', 'url' => 'https://evil.example']],
    [['local' => true, 'visibility' => 'private', 'account' => ['username' => 'bob']]],
    [['local' => true, 'visibility' => 'public', 'account' => ['username' => '//evil.example']]],
]);

it('rejects malformed post route identifiers before authenticated rendering', function (string $id) {
    config(['exp.spa' => true]);
    $request = Request::create('/i/web/post/123');
    $request->setUserResolver(fn () => new User);
    try {
        (new SpaController)->webPost($request, $id);
        $this->fail('Malformed ID was accepted');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(404);
    }
})->with(['abc', '123/', '123?foo=bar', '123#fragment', '../123', '%31%32%33', '//evil.example', 'https://evil.example', '123\\evil']);

it('renders the authenticated cached post route locally', function () {
    config(['exp.spa' => true]);
    $request = Request::create('/i/web/post/300');
    $request->setUserResolver(fn () => new User);
    expect((new SpaController)->webPost($request, '300')->name())->toBe('layouts.spa');
});

it('keeps private cached post data inaccessible to an authenticated non-follower', function () {
    require_once __DIR__.'/../../Support/WebPushFollowedPostFixture.php';
    setupWebPushFollowedPostFixture($this);
    try {
        Cache::put('user:last_active_at:id:3', true);
        Redis::shouldReceive('zCard')->andReturn(0);
        Cache::put(StatusService::key('300', false), ['id' => '300', 'local' => true, 'visibility' => 'private', 'reply_count' => 0,
            'account' => ['id' => '20', 'username' => 'bob', 'acct' => 'bob', 'url' => 'https://pixelfed.example/users/bob']]);
        $request = Request::create('/api/v1/statuses/300', 'GET', ['_pe' => true]);
        $request->setUserResolver(fn () => User::find(3)->withAccessToken(new AccessToken(['oauth_scopes' => ['read']])));
        try {
            (new ApiV1Controller)->statusById($request, '300');
            $this->fail('Private content was returned');
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(403);
        }
    } finally {
        tearDownWebPushCommentFixture();
    }
});

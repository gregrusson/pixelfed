<?php

use App\Models\FollowRequest;
use App\Models\User;
use App\Util\Site\Config as SiteConfig;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Http\Middleware\CreateFreshApiToken;

require_once __DIR__.'/../../Support/WebPushFollowRequestFixture.php';

class EnforcedFollowRequestForgeryProtection extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}

beforeEach(function () {
    setupWebPushFollowRequestFixture($this);
    Queue::fake();
    $domain = app('router')->getRoutes()->getByName('follow-requests')->getDomain();
    $this->managementUrl = 'https://'.$domain.'/account/follow-requests';
    config(['app.url' => 'https://'.$domain, 'instance.restricted.enabled' => false]);
    $this->withoutMiddleware(CreateFreshApiToken::class);
    Cache::put(SiteConfig::CACHE_KEY, []);
    // Laravel normally bypasses forgery checking in tests. Exercise the actual
    // route middleware with only that testing bypass disabled.
    app()->bind(PreventRequestForgery::class, EnforcedFollowRequestForgeryProtection::class);
});
afterEach(fn () => tearDownWebPushCommentFixture());

it('requires authentication for management and its actions', function () {
    $this->get($this->managementUrl)->assertRedirect();
    $this->getJson($this->managementUrl)->assertUnauthorized();
    $this->withSession(['_token' => 'valid-token'])->postJson($this->managementUrl, ['_token' => 'valid-token', 'action' => 'reject', 'id' => 1])->assertUnauthorized();
});

it('renders management locally and ignores redirect destinations', function () {
    $this->actingAs(User::find(1));
    $response = $this->get($this->managementUrl.'?redirect=https://evil.example&url=//evil.example&next=https://evil.example');
    $response->assertOk()->assertViewIs('account.follow-requests')->assertSee('Follow Requests');
    expect($response->headers->has('Location'))->toBeFalse();
});

it('requires a valid CSRF token for management actions', function () {
    $pending = FollowRequest::withoutEvents(fn () => createPendingWebPushRequest());
    $this->actingAs(User::find(1));
    $this->withSession(['_token' => 'valid-token'])->postJson($this->managementUrl, [
        'action' => 'reject', 'id' => $pending->id,
    ])->assertStatus(419);
    expect($pending->fresh())->not->toBeNull();
    $this->withSession(['_token' => 'valid-token'])->postJson($this->managementUrl, [
        '_token' => 'valid-token', 'action' => 'reject', 'id' => 999,
    ])->assertNotFound(); // Valid token reaches recipient-scoped lookup.
    expect($pending->fresh())->not->toBeNull();
});

it('scopes accept and reject request IDs to the authenticated recipient', function (string $action) {
    $pending = FollowRequest::withoutEvents(fn () => createPendingWebPushRequest());
    $this->actingAs(User::find(3));
    $this->withSession(['_token' => 'valid-token'])->postJson($this->managementUrl, [
        '_token' => 'valid-token', 'action' => $action, 'id' => $pending->id,
    ])->assertNotFound();
    expect($pending->fresh())->not->toBeNull();
})->with(['accept', 'reject']);

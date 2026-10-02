<?php

use App\Http\Controllers\SpaController;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

it('renders the numeric profile SPA locally for authenticated local and remote accounts', function (bool $local) {
    config(['exp.spa' => true]);
    Cache::put(AccountService::CACHE_KEY.'20', [
        'id' => '20', 'local' => $local, 'url' => 'https://evil.example/remote',
    ]);
    $request = Request::create('/i/web/profile/20');
    $request->setUserResolver(fn () => new User);
    $view = (new SpaController)->webProfile($request, '20');
    expect($view->name())->toBe('layouts.spa');
})->with([true, false]);

it('constructs safe local redirects regardless of the stored account URL', function (string $url) {
    config(['exp.spa' => true]);
    Cache::put(AccountService::CACHE_KEY.'20', [
        'id' => '20', 'local' => true, 'username' => 'bob.name-1', 'url' => $url,
    ]);
    $response = (new SpaController)->webProfile(Request::create('/i/web/profile/20'), '20');
    expect($response->getTargetUrl())->toBe(url('/bob.name-1'));
})->with(['https://pixelfed.example/bob', 'https://evil.example/escape', '//evil.example/escape']);

it('uses local login for remote accounts and unsafe local usernames', function (array $account) {
    config(['exp.spa' => true]);
    Cache::put(AccountService::CACHE_KEY.'20', $account);
    $response = (new SpaController)->webProfile(Request::create('/i/web/profile/20'), '20');
    expect($response->getTargetUrl())->toBe(url('/login'));
})->with([
    [['local' => false, 'username' => '@bob@remote.example', 'url' => 'https://remote.example/bob']],
    [['local' => true, 'username' => '//evil.example', 'url' => 'https://evil.example']],
    [['local' => true, 'username' => '..', 'url' => 'https://evil.example']],
    [['local' => true, 'username' => 'bob?next=https://evil.example', 'url' => 'https://evil.example']],
]);

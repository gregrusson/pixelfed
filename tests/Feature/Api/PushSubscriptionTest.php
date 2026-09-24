<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Passport\Passport;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\VAPID;
use NotificationChannels\WebPush\PushSubscription;

uses(LazilyRefreshDatabase::class);

class RacingPushSubscription extends PushSubscription
{
    public static bool $missFirstLookup = false;

    public static function findByEndpoint(string $endpoint): ?static
    {
        if (static::$missFirstLookup) {
            static::$missFirstLookup = false;

            return null;
        }

        return parent::findByEndpoint($endpoint);
    }
}

beforeEach(function () {
    $keys = VAPID::createVapidKeys();
    config([
        'webpush.vapid.subject' => 'mailto:admin@example.com',
        'webpush.vapid.public_key' => $keys['publicKey'],
        'webpush.vapid.private_key' => $keys['privateKey'],
    ]);
});

function pushSubscriptionPayload(string $endpoint = 'https://push.example.com/subscription'): array
{
    return [
        'subscription' => [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => 'browser-public-key',
                'auth' => 'browser-auth-secret',
            ],
        ],
    ];
}

it('rejects unauthenticated registration and deletion', function () {
    $this->postJson('/api/v1/push/subscription', pushSubscriptionPayload())->assertUnauthorized();
    $this->deleteJson('/api/v1/push/subscription', ['subscription' => ['endpoint' => 'https://push.example.com/subscription']])
        ->assertUnauthorized();
});

it('validates the registration payload', function () {
    Passport::actingAs(User::factory()->create(), ['push']);

    $this->postJson('/api/v1/push/subscription', [
        'subscription' => [
            'endpoint' => str_repeat('x', 501),
            'keys' => ['p256dh' => str_repeat('x', 192)],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'subscription.endpoint',
        'subscription.keys.p256dh',
        'subscription.keys.auth',
    ]);
});

it('creates a subscription with aes128gcm without changing notification preferences', function () {
    $user = User::factory()->create(['notify_enabled' => false]);
    Passport::actingAs($user, ['push']);

    $response = $this->postJson('/api/v1/push/subscription', pushSubscriptionPayload())
        ->assertOk()
        ->assertJsonPath('endpoint', 'https://push.example.com/subscription')
        ->assertJsonPath('server_key', config('webpush.vapid.public_key'))
        ->assertJsonStructure(['id', 'endpoint', 'server_key']);

    $subscription = $user->pushSubscriptions()->sole();
    expect($response->json('id'))->toBe((string) $subscription->id)
        ->and($subscription->public_key)->toBe('browser-public-key')
        ->and($subscription->auth_token)->toBe('browser-auth-secret')
        ->and($subscription->content_encoding)->toBe(ContentEncoding::aes128gcm)
        ->and((bool) $user->fresh()->notify_enabled)->toBeFalse();
});

it('updates the current user subscription when registered again', function () {
    $user = User::factory()->create();
    Passport::actingAs($user, ['push']);
    $first = $this->postJson('/api/v1/push/subscription', pushSubscriptionPayload())->assertOk();

    $payload = pushSubscriptionPayload();
    $payload['subscription']['keys']['auth'] = 'new-browser-auth-secret';
    $this->postJson('/api/v1/push/subscription', $payload)
        ->assertOk()
        ->assertJsonPath('id', $first->json('id'));

    expect($user->pushSubscriptions()->count())->toBe(1)
        ->and($user->pushSubscriptions()->sole()->auth_token)->toBe('new-browser-auth-secret');
});

it('does not allow one user to take over another user endpoint', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    Passport::actingAs($owner, ['push']);
    $this->postJson('/api/v1/push/subscription', pushSubscriptionPayload())->assertOk();
    $original = $owner->pushSubscriptions()->sole();

    Passport::actingAs($other, ['push']);
    $payload = pushSubscriptionPayload();
    $payload['subscription']['keys']['auth'] = 'attacker-secret';
    $this->postJson('/api/v1/push/subscription', $payload)->assertStatus(409);

    expect($owner->pushSubscriptions()->count())->toBe(1)
        ->and($owner->pushSubscriptions()->sole()->id)->toBe($original->id)
        ->and($owner->pushSubscriptions()->sole()->auth_token)->toBe('browser-auth-secret')
        ->and($other->pushSubscriptions()->count())->toBe(0);
});

it('returns a conflict if another user claims the endpoint after the initial lookup', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $owner->pushSubscriptions()->create([
        'endpoint' => 'https://push.example.com/subscription',
        'public_key' => 'owner-key',
        'auth_token' => 'owner-secret',
        'content_encoding' => ContentEncoding::aes128gcm,
    ]);

    config(['webpush.model' => RacingPushSubscription::class]);
    RacingPushSubscription::$missFirstLookup = true;
    Passport::actingAs($other, ['push']);

    $this->postJson('/api/v1/push/subscription', pushSubscriptionPayload())->assertStatus(409);

    expect($owner->pushSubscriptions()->sole()->auth_token)->toBe('owner-secret')
        ->and($other->pushSubscriptions()->count())->toBe(0);
});

it('deletes only the authenticated user subscription', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    Passport::actingAs($owner, ['push']);
    $this->postJson('/api/v1/push/subscription', pushSubscriptionPayload())->assertOk();

    Passport::actingAs($other, ['push']);
    $this->deleteJson('/api/v1/push/subscription', ['subscription' => ['endpoint' => 'https://push.example.com/subscription']])
        ->assertOk()->assertExactJson([]);
    expect($owner->pushSubscriptions()->count())->toBe(1);

    Passport::actingAs($owner, ['push']);
    $this->deleteJson('/api/v1/push/subscription', ['subscription' => ['endpoint' => 'https://push.example.com/subscription']])
        ->assertOk()->assertExactJson([]);
    expect($owner->pushSubscriptions()->count())->toBe(0);
});

it('rejects registration without valid VAPID configuration', function () {
    $user = User::factory()->create();
    Passport::actingAs($user, ['push']);
    $privateKey = config('webpush.vapid.private_key');
    config(['webpush.vapid.private_key' => null]);

    $this->postJson('/api/v1/push/subscription', pushSubscriptionPayload())->assertStatus(503);
    expect($user->pushSubscriptions()->count())->toBe(0);

    config(['webpush.vapid.private_key' => 'invalid-key']);
    $this->postJson('/api/v1/push/subscription', pushSubscriptionPayload())->assertStatus(503);
    expect($user->pushSubscriptions()->count())->toBe(0);

    config(['webpush.vapid.private_key' => '%']);
    $this->postJson('/api/v1/push/subscription', pushSubscriptionPayload())->assertStatus(503);
    expect($user->pushSubscriptions()->count())->toBe(0);

    config([
        'webpush.vapid.private_key' => $privateKey,
        'webpush.vapid.subject' => 'invalid-subject',
    ]);
    $this->postJson('/api/v1/push/subscription', pushSubscriptionPayload())->assertStatus(503);
    expect($user->pushSubscriptions()->count())->toBe(0);
});

it('accepts an HTTPS VAPID subject and rejects HTTP', function () {
    $user = User::factory()->create();
    Passport::actingAs($user, ['push']);
    config(['webpush.vapid.subject' => 'https://example.com/contact']);

    $this->postJson('/api/v1/push/subscription', pushSubscriptionPayload())->assertOk();

    config(['webpush.vapid.subject' => 'http://example.com/contact']);
    $this->postJson('/api/v1/push/subscription', pushSubscriptionPayload('https://push.example.com/another'))
        ->assertStatus(503);
    expect($user->pushSubscriptions()->count())->toBe(1);
});

it('allows a first-party session to register a subscription', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'web')
        ->withHeader('Origin', config('app.url'))
        ->postJson('/api/v1/push/subscription', pushSubscriptionPayload())
        ->assertOk();

    expect($user->pushSubscriptions()->count())->toBe(1);
});

it('requires the push OAuth scope for registration and deletion', function () {
    $user = User::factory()->create();
    Passport::actingAs($user, ['write']);

    $this->postJson('/api/v1/push/subscription', pushSubscriptionPayload())->assertForbidden();
    $this->deleteJson('/api/v1/push/subscription', ['subscription' => ['endpoint' => 'https://push.example.com/subscription']])
        ->assertForbidden();
    expect($user->pushSubscriptions()->count())->toBe(0);
});

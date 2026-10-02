<?php

use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use App\Services\WebPush\BoundedDnsResolver;
use App\Services\WebPush\DeliveryService;
use App\Services\WebPushNotificationService;
use App\Util\ActivityPub\Helpers;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\VAPID;

require_once __DIR__.'/../../Support/WebPushCommentFixture.php';

beforeEach(function () {
    setupWebPushCommentFixture($this);
    Queue::fake();
});
afterEach(fn () => tearDownWebPushCommentFixture());

it('queues the exact safe local comment payload using browser opt-in with the mobile gate disabled', function () {
    $notification = createCommentPushNotification();
    Queue::assertPushed(DeliverWebPush::class, function ($job) {
        expect($job->payload)->toBe([
            'notification_type' => 'comment', 'title' => 'New Comment',
            'body' => '@bob commented on your post', 'account_id' => '20',
            'status_id' => '200', 'parent_status_id' => '100', 'url' => '/p/alice/100',
        ])->and($job->userId)->toBe('1')->and($job->subscriptionId)->toBe('1')
            ->and($job->connection)->toBe('redis')->and($job->queue)->toBe('pushnotify')
            ->and($job->afterCommit)->toBeTrue()->and($job->expiresAt)->toBeGreaterThanOrEqual(time() + 179);
        expect(serialize($job))->not->toContain('private comment text', 'secret-', 'push.example.com', 'parent text');

        return true;
    });
    Queue::assertPushed(DeliverWebPush::class, 1);
    expect(array_intersect_key($notification->fresh()->getAttributes(), $notification->getAttributes()))->toEqual($notification->getAttributes());
});

it('accepts a federated actor and reply without exposing their URI', function () {
    DB::table('profiles')->where('id', 20)->update(['user_id' => null, 'domain' => 'remote.example', 'username' => '@bob@remote.example']);
    DB::table('statuses')->where('id', 200)->update(['uri' => 'https://remote.example/private-uri']);
    createCommentPushNotification();
    Queue::assertPushed(DeliverWebPush::class, fn ($job) => $job->payload['body'] === '@bob@remote.example commented on your post'
        && ! str_contains(serialize($job), 'private-uri'));
    Queue::assertPushed(DeliverWebPush::class, 1);
});

it('supports importer-valid remote punctuation and normalized length without duplicate pushes', function (string $username) {
    expect(Helpers::extractUsername(['preferredUsername' => explode('@', substr($username, 1))[0]]))->not->toBeNull();
    DB::table('profiles')->where('id', 20)->update(['domain' => 'remote.example', 'username' => $username]);
    $notification = createCommentPushNotification();
    WebPushNotificationService::notify($notification);
    createCommentPushNotification(['action' => 'mention']);
    Queue::assertPushed(DeliverWebPush::class, fn ($job) => $job->payload['body'] === $username.' commented on your post');
    Queue::assertPushed(DeliverWebPush::class, 1);
})->with(['@.bob@remote.example', '@-bob@remote.example', '@'.str_repeat('b', 240).'@remote.example']);

it('rejects malformed remote usernames after removing at most one marker', function (string $username) {
    DB::table('profiles')->where('id', 20)->update(['domain' => 'remote.example', 'username' => $username]);
    createCommentPushNotification();
    Queue::assertNothingPushed();
    expect($this->claims)->toBeEmpty();
})->with(['@@bob@remote.example', '@@@bob@remote.example', '@', '@.@remote.example', '@-@remote.example',
    '@bob smith@remote.example', "@bob\n@remote.example", "@bob\0@remote.example", '@bob/evil@remote.example',
    '@bob\\evil@remote.example', '@<b>bob</b>@remote.example', '@bob:443@remote.example', '@bob?x@remote.example',
    '@bob#x@remote.example', '@bob%2f@remote.example', '@'.str_repeat('b', 241).'@remote.example']);

it('keeps local leading character validation unchanged', function (string $username, bool $allowed) {
    DB::table('profiles')->where('id', 20)->update(['username' => $username]);
    createCommentPushNotification();
    if ($allowed) {
        Queue::assertPushed(DeliverWebPush::class, fn ($job) => $job->payload['body'] === '@'.$username.' commented on your post');
        Queue::assertPushed(DeliverWebPush::class, 1);
    } else {
        Queue::assertNothingPushed();
    }
})->with([['bob', true], ['_bob', true], ['@bob', false], ['.bob', false], ['-bob', false]]);

it('rejects self comments independently of pipeline checks', function () {
    DB::table('statuses')->where('id', 200)->update(['profile_id' => 10]);
    createCommentPushNotification(['actor_id' => 10]);
    Queue::assertNothingPushed();
});

it('requires a subscription and the comment preference', function (string $reason) {
    if ($reason === 'no_subscription') {
        $this->subscription->delete();
    } else {
        DB::table('users')->where('id', 1)->update(['notify_comment' => false]);
    }
    createCommentPushNotification();
    Queue::assertNothingPushed();
    expect($this->claims)->toBeEmpty();
})->with(['no_subscription', 'preference']);

it('queues once per owned subscription and never to an unrelated subscriber', function () {
    User::find(1)->pushSubscriptions()->create(['endpoint' => 'https://push.example.com/second']);
    User::find(3)->pushSubscriptions()->create(['endpoint' => 'https://push.example.com/unrelated']);
    createCommentPushNotification();
    Queue::assertPushed(DeliverWebPush::class, 2);
    Queue::assertNotPushed(DeliverWebPush::class, fn ($job) => $job->userId !== '1');
    expect($this->claims)->toHaveCount(2);
});

it('targets the immediate parent author for nested replies', function () {
    DB::table('statuses')->insert(['id' => 300, 'profile_id' => 30, 'in_reply_to_id' => 200, 'in_reply_to_profile_id' => 20]);
    User::find(2)->pushSubscriptions()->create(['endpoint' => 'https://push.example.com/bob']);
    createCommentPushNotification(['profile_id' => 20, 'actor_id' => 30, 'item_id' => 300]);
    Queue::assertPushed(DeliverWebPush::class, fn ($job) => $job->userId === '2' && $job->payload['parent_status_id'] === '200');
    Queue::assertPushed(DeliverWebPush::class, 1);
});

it('normalizes owner comment and mention events in either order without altering internal rows', function (array $actions) {
    $first = createCommentPushNotification(['action' => $actions[0]]);
    WebPushNotificationService::notify($first);
    createCommentPushNotification(['action' => $actions[1]]);
    createCommentPushNotification(['action' => $actions[0]]);
    Queue::assertPushed(DeliverWebPush::class, 1);
    expect(Notification::count())->toBe(3)->and(Notification::orderBy('id')->pluck('action')->all())->toBe([$actions[0], $actions[1], $actions[0]])
        ->and(array_keys($this->claims))->toBe(['test:webpush:comment:1:200:1']);
})->with([['actions' => ['comment', 'mention']], ['actions' => ['mention', 'comment']]]);

it('does not classify general mentions or group events as comments', function (array $attributes) {
    createCommentPushNotification($attributes);
    Queue::assertNothingPushed();
})->with([
    ['attributes' => ['action' => 'mention', 'profile_id' => 30]],
    ['attributes' => ['action' => 'mention', 'item_id' => 100]],
    ['attributes' => ['action' => 'group:comment']],
    ['attributes' => ['item_type' => Profile::class]],
    ['attributes' => ['action' => 'like']],
]);

it('omits URLs for non-public contexts', function (string $scope) {
    DB::table('statuses')->where('id', 100)->update(['scope' => $scope]);
    createCommentPushNotification();
    Queue::assertPushed(DeliverWebPush::class, fn ($job) => ! array_key_exists('url', $job->payload));
})->with(['private', 'direct', 'unlisted']);

it('rejects missing deleted inconsistent remote and filtered recipients safely', function (string $case) {
    match ($case) {
        'missing_parent' => DB::table('statuses')->where('id', 100)->delete(),
        'deleted_comment' => DB::table('statuses')->where('id', 200)->update(['deleted_at' => now()]),
        'deleted_actor' => DB::table('profiles')->where('id', 20)->update(['deleted_at' => now()]),
        'deleted_recipient' => DB::table('profiles')->where('id', 10)->update(['deleted_at' => now()]),
        'deleted_user' => DB::table('users')->where('id', 1)->update(['deleted_at' => now()]),
        'remote_recipient' => DB::table('profiles')->where('id', 10)->update(['domain' => 'remote.example']),
        'wrong_parent_owner' => DB::table('statuses')->where('id', 200)->update(['in_reply_to_profile_id' => 30]),
        'wrong_actor' => DB::table('statuses')->where('id', 200)->update(['profile_id' => 30]),
        'wrong_user_owner' => DB::table('users')->where('id', 1)->update(['profile_id' => 30]),
        'disabled_comments' => DB::table('statuses')->where('id', 100)->update(['comments_disabled' => true]),
        'group' => DB::table('statuses')->where('id', 200)->update(['group_id' => 99]),
        'markup_username' => DB::table('profiles')->where('id', 20)->update(['username' => '<b>bob</b>']),
        'long_username' => DB::table('profiles')->where('id', 20)->update(['username' => str_repeat('a', 256)]),
        default => DB::table('user_filters')->insert(['user_id' => 10, 'filterable_id' => 20, 'filterable_type' => Profile::class, 'filter_type' => $case]),
    };
    createCommentPushNotification();
    Queue::assertNothingPushed();
    expect($this->claims)->toBeEmpty()->and(Notification::count())->toBe(1);
})->with(['missing_parent', 'deleted_comment', 'deleted_actor', 'deleted_recipient', 'deleted_user', 'remote_recipient',
    'wrong_parent_owner', 'wrong_actor', 'wrong_user_owner', 'disabled_comments', 'group', 'markup_username', 'long_username', 'mute', 'block']);

it('supports legacy status item types', function () {
    createCommentPushNotification(['item_type' => 'App\\Status']);
    Queue::assertPushed(DeliverWebPush::class, 1);
});

it('runs real local configuration validation without DNS HTTP or delivery', function () {
    $keys = VAPID::createVapidKeys();
    config(['webpush.vapid' => ['subject' => 'mailto:admin@example.com', 'public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey']]]);
    $delivery = Mockery::mock(DeliveryService::class, [app(BoundedDnsResolver::class)])->makePartial();
    $delivery->shouldNotReceive('send');
    $this->app->instance(DeliveryService::class, $delivery);
    createCommentPushNotification();
    Queue::assertPushed(DeliverWebPush::class, 1);
});

it('leaves persisted notifications intact if Redis claiming or logging fails', function () {
    $this->redisWire->shouldReceive('set')->andThrow(new RuntimeException('secret-endpoint'));
    Log::shouldReceive('warning')->andThrow(new RuntimeException('secret-key'));
    createCommentPushNotification();
    expect(Notification::count())->toBe(1);
    Queue::assertNothingPushed();
});

it('does not fall back to a nonpersistent default store for deduplication', function () {
    Cache::extend('redis', fn () => new Repository(new ArrayStore));
    Cache::forgetDriver('redis');
    createCommentPushNotification();
    Queue::assertNothingPushed();
    expect($this->claims)->toBeEmpty();
});

it('isolates configuration failures and logs no exception secrets', function () {
    Log::spy();
    $this->delivery->shouldReceive('validateConfiguration')->andThrow(new RuntimeException('secret-endpoint secret-vapid'));
    createCommentPushNotification();
    Queue::assertNothingPushed();
    expect(Notification::count())->toBe(1)->and($this->claims)->toBeEmpty();
    Log::shouldHaveReceived('warning')->once()->with('Web Push comment: orchestration_failed');
});

it('rejects invalid queue settings before claiming an event', function (string $key, mixed $value) {
    config([$key => $value]);
    createCommentPushNotification();
    Queue::assertNothingPushed();
    expect($this->claims)->toBeEmpty();
})->with([
    ['webpush.delivery.connection', 'sync'], ['webpush.delivery.queue', 'bad/queue'],
    ['webpush.delivery.ttl', 0], ['webpush.delivery.ttl', 301], ['webpush.delivery.ttl', '300'],
]);

it('creates no claim if dispatcher preparation fails', function () {
    $this->redisWire->shouldNotReceive('set');
    $this->app->bind(Dispatcher::class, fn () => throw new RuntimeException('secret'));
    createCommentPushNotification();
    expect($this->claims)->toBeEmpty()->and(Notification::count())->toBe(1);
});

it('creates no claim if queue connection preparation fails', function () {
    $this->redisWire->shouldNotReceive('set');
    $factory = Mockery::mock(QueueFactory::class);
    $factory->shouldReceive('connection')->once()->with('redis')->andThrow(new RuntimeException('secret'));
    $this->app->instance(QueueFactory::class, $factory);
    createCommentPushNotification();
    expect($this->claims)->toBeEmpty()->and(Notification::count())->toBe(1);
});

it('retains ambiguous enqueue claims and isolates failures for each subscription', function () {
    User::find(1)->pushSubscriptions()->create(['endpoint' => 'https://push.example.com/second']);
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->twice()->andThrow(new RuntimeException('secret'));
    $this->app->instance(Dispatcher::class, $dispatcher);
    createCommentPushNotification();
    expect($this->claims)->toHaveCount(2)->and(Notification::count())->toBe(1);
});

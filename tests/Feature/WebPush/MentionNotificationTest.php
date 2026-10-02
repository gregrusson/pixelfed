<?php

use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Models\Mention;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use App\Services\WebPush\BoundedDnsResolver;
use App\Services\WebPush\DeliveryService;
use App\Services\WebPushNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Minishlink\WebPush\VAPID;

require_once __DIR__.'/../../Support/WebPushMentionFixture.php';

beforeEach(function () {
    setupWebPushMentionFixture($this);
    Queue::fake();
});
afterEach(fn () => tearDownWebPushCommentFixture());

it('queues the exact local mention payload with browser opt-in and mobile notifications disabled', function () {
    config(['webpush.vapid.private_key' => 'vapid-secret']);
    $notification = createMentionPushNotification();
    Queue::assertPushed(DeliverWebPush::class, function ($job) {
        expect($job->payload)->toBe([
            'notification_type' => 'mention', 'title' => 'New Mention',
            'body' => '@bob mentioned you', 'account_id' => '20',
            'status_id' => '300', 'url' => '/p/bob/300',
        ])->and($job->userId)->toBe('1')->and($job->subscriptionId)->toBe('1')
            ->and($job->connection)->toBe('redis')->and($job->queue)->toBe('pushnotify')
            ->and($job->afterCommit)->toBeTrue();
        expect(serialize($job))->not->toContain('private caption secret', 'rendered secret', '<p>',
            'secret-endpoint', 'secret-public-key', 'secret-auth', 'vapid-secret');

        return true;
    });
    Queue::assertPushed(DeliverWebPush::class, 1);
    expect($notification->fresh()->action)->toBe('mention')
        ->and((bool) User::find(1)->notify_enabled)->toBeFalse();
});

it('classifies non-owner reply and ancestor mentions as mentions', function (bool $nested) {
    DB::table('statuses')->where('id', 300)->update([
        'profile_id' => 30, 'in_reply_to_id' => $nested ? 200 : 100,
        'in_reply_to_profile_id' => $nested ? 20 : 10,
    ]);
    $recipient = $nested ? 10 : 20;
    Mention::create(['status_id' => 300, 'profile_id' => $recipient]);
    if (! $nested) {
        User::find(2)->pushSubscriptions()->create(['endpoint' => 'https://push.example.com/bob']);
    }
    createMentionPushNotification(['profile_id' => $recipient, 'actor_id' => 30]);
    Queue::assertPushed(DeliverWebPush::class, fn ($job) => $job->payload['notification_type'] === 'mention'
        && $job->payload['status_id'] === '300' && $job->payload['url'] === '/p/charlie/300');
    Queue::assertPushed(DeliverWebPush::class, 1);
})->with([false, true]);

it('normalizes owner mentions and comment rows to one comment in either order', function (array $actions) {
    Mention::create(['status_id' => 200, 'profile_id' => 10]);
    foreach ($actions as $action) {
        createMentionPushNotification(['item_id' => 200, 'action' => $action]);
    }
    Queue::assertPushed(DeliverWebPush::class, fn ($job) => $job->payload['notification_type'] === 'comment'
        && $job->payload['url'] === '/p/alice/100');
    Queue::assertPushed(DeliverWebPush::class, 1);
    expect(array_keys($this->claims))->toBe(['test:webpush:comment:1:200:1']);
})->with([['actions' => ['comment', 'mention']], ['actions' => ['mention', 'comment']]]);

it('never falls back from rejected owner comments to mention delivery', function (string $reason) {
    Mention::create(['status_id' => 200, 'profile_id' => 10]);
    match ($reason) {
        'preference' => DB::table('users')->where('id', 1)->update(['notify_comment' => false]),
        'disabled' => DB::table('statuses')->where('id', 100)->update(['comments_disabled' => true]),
        'metadata' => DB::table('statuses')->where('id', 200)->update(['in_reply_to_profile_id' => 30]),
        default => DB::table('user_filters')->insert([
            'user_id' => 10, 'filterable_id' => 20, 'filterable_type' => Profile::class, 'filter_type' => $reason,
        ]),
    };
    createMentionPushNotification(['item_id' => 200]);
    createMentionPushNotification(['item_id' => 200, 'action' => 'comment']);
    Queue::assertNothingPushed();
    expect($this->claims)->toBeEmpty();
})->with(['preference', 'disabled', 'metadata', 'mute', 'block']);

it('uses only the classified event preference', function (bool $owner) {
    DB::table('users')->where('id', 1)->update([
        'notify_comment' => $owner, 'notify_mention' => ! $owner,
    ]);
    createMentionPushNotification(['item_id' => $owner ? 200 : 300]);
    Queue::assertPushed(DeliverWebPush::class, fn ($job) => $job->payload['notification_type'] === ($owner ? 'comment' : 'mention'));
    Queue::assertPushed(DeliverWebPush::class, 1);
})->with([false, true]);

it('rejects invalid or unsupported mention records without claiming', function (string $case) {
    $attributes = [];
    match ($case) {
        'self' => $attributes = ['profile_id' => 20],
        'remote_recipient' => DB::table('profiles')->where('id', 10)->update(['domain' => 'remote.example']),
        'missing_status' => DB::table('statuses')->where('id', 300)->delete(),
        'deleted_status' => DB::table('statuses')->where('id', 300)->update(['deleted_at' => now()]),
        'deleted_actor' => DB::table('profiles')->where('id', 20)->update(['deleted_at' => now()]),
        'deleted_recipient' => DB::table('profiles')->where('id', 10)->update(['deleted_at' => now()]),
        'deleted_user' => DB::table('users')->where('id', 1)->update(['deleted_at' => now()]),
        'wrong_user' => DB::table('users')->where('id', 1)->update(['profile_id' => 30]),
        'missing_user' => DB::table('profiles')->where('id', 10)->update(['user_id' => null]),
        'wrong_actor' => $attributes = ['actor_id' => 30],
        'missing_parent' => DB::table('statuses')->where('id', 300)->update(['in_reply_to_id' => 999, 'in_reply_to_profile_id' => 30]),
        'deleted_parent' => (function () {
            DB::table('statuses')->where('id', 300)->update(['in_reply_to_id' => 200, 'in_reply_to_profile_id' => 20]);
            DB::table('statuses')->where('id', 200)->update(['deleted_at' => now()]);
        })(),
        'wrong_parent' => DB::table('statuses')->where('id', 300)->update(['in_reply_to_id' => 200, 'in_reply_to_profile_id' => 30]),
        'group_parent' => (function () {
            DB::table('statuses')->where('id', 300)->update(['in_reply_to_id' => 200, 'in_reply_to_profile_id' => 20]);
            DB::table('statuses')->where('id', 200)->update(['group_id' => 99]);
        })(),
        'no_mention' => DB::table('mentions')->delete(),
        'deleted_mention' => DB::table('mentions')->update(['deleted_at' => now()]),
        'wrong_mention_recipient' => DB::table('mentions')->update(['profile_id' => 30]),
        'wrong_mention_status' => DB::table('mentions')->update(['status_id' => 100]),
        'group' => DB::table('statuses')->where('id', 300)->update(['group_id' => 99]),
        'group_scope', 'direct', 'draft' => DB::table('statuses')->where('id', 300)->update(['scope' => $case === 'group_scope' ? 'group' : $case]),
        'preference' => DB::table('users')->where('id', 1)->update(['notify_mention' => false]),
        'no_subscription' => $this->subscription->delete(),
        'wrong_type' => $attributes = ['item_type' => Profile::class],
        'comment_top_level' => $attributes = ['action' => 'comment'],
    };
    createMentionPushNotification($attributes);
    Queue::assertNothingPushed();
    expect($this->claims)->toBeEmpty()->and(Notification::count())->toBe(1);
})->with(['self', 'remote_recipient', 'missing_status', 'deleted_status', 'deleted_actor', 'deleted_recipient',
    'deleted_user', 'wrong_user', 'missing_user', 'wrong_actor', 'missing_parent', 'deleted_parent', 'wrong_parent',
    'group_parent', 'no_mention', 'deleted_mention', 'wrong_mention_recipient', 'wrong_mention_status',
    'group', 'group_scope', 'direct', 'draft', 'preference', 'no_subscription', 'wrong_type', 'comment_top_level']);

it('suppresses recipient mutes and blocks for local and federated mentions', function (string $filter, bool $remote) {
    if ($remote) {
        DB::table('profiles')->where('id', 20)->update(['domain' => 'remote.example', 'username' => '@bob@remote.example', 'user_id' => null]);
        DB::table('statuses')->where('id', 300)->update(['local' => false, 'uri' => 'https://remote.example/status/300']);
    }
    DB::table('user_filters')->insert([
        'user_id' => 10, 'filterable_id' => 20, 'filterable_type' => Profile::class, 'filter_type' => $filter,
    ]);
    createMentionPushNotification();
    Queue::assertNothingPushed();
    expect($this->claims)->toBeEmpty();
})->with(['mute', 'block'])->with([false, true]);

it('sends supported visible mentions and links the containing local status', function (string $scope, bool $authorized) {
    DB::table('statuses')->where('id', 300)->update(['scope' => $scope]);
    if ($authorized) {
        DB::table('followers')->insert(['profile_id' => 10, 'following_id' => 20]);
    }
    createMentionPushNotification();
    if ($scope === 'private' && ! $authorized) {
        Queue::assertNothingPushed();
        expect($this->claims)->toBeEmpty();
    } else {
        Queue::assertPushed(DeliverWebPush::class, fn ($job) => $job->payload['url'] === '/p/bob/300');
        Queue::assertPushed(DeliverWebPush::class, 1);
    }
})->with([['public', false], ['unlisted', false], ['private', true], ['private', false]]);

it('normalizes federated usernames without leaking a remote status destination', function (string $username) {
    DB::table('profiles')->where('id', 20)->update(['domain' => 'remote.example', 'username' => $username, 'user_id' => null]);
    DB::table('statuses')->where('id', 300)->update(['local' => false, 'uri' => 'https://remote.example/secret-uri']);
    createMentionPushNotification();
    Queue::assertPushed(DeliverWebPush::class, function ($job) use ($username) {
        expect($job->payload['body'])->toBe($username.' mentioned you');
        expect($job->payload)->not->toHaveKey('url')->not->toHaveKey('parent_status_id');
        expect(serialize($job))->not->toContain('secret-uri', 'https://remote.example', 'private caption secret', 'rendered secret');

        return true;
    });
    Queue::assertPushed(DeliverWebPush::class, 1);
})->with(['@bob@remote.example', '@.bob@remote.example', '@-bob@remote.example']);

it('omits ambiguous local destinations while retaining the mention', function (string $case) {
    match ($case) {
        'punctuation' => DB::table('profiles')->where('id', 20)->update(['username' => 'bob.example']),
        'at' => DB::table('profiles')->where('id', 20)->update(['username' => 'bob@example']),
        'uri' => DB::table('statuses')->where('id', 300)->update(['uri' => 'https://remote.example/secret-uri']),
        'not_local' => DB::table('statuses')->where('id', 300)->update(['local' => false]),
    };
    createMentionPushNotification();
    Queue::assertPushed(DeliverWebPush::class, fn ($job) => ! isset($job->payload['url']));
    Queue::assertPushed(DeliverWebPush::class, 1);
})->with(['punctuation', 'at', 'uri', 'not_local']);

it('rejects unsafe body usernames', function (string $username) {
    DB::table('profiles')->where('id', 20)->update(['username' => $username]);
    createMentionPushNotification();
    Queue::assertNothingPushed();
    expect($this->claims)->toBeEmpty();
})->with(['bob/evil', "bob\n", '<b>bob</b>', 'bob%2f', 'álîce']);

it('deduplicates rows invocations and repeated mention records per owned subscription', function () {
    User::find(1)->pushSubscriptions()->create(['endpoint' => 'https://push.example.com/second']);
    User::find(3)->pushSubscriptions()->create(['endpoint' => 'https://push.example.com/unrelated']);
    $notification = createMentionPushNotification();
    WebPushNotificationService::notify($notification);
    Mention::create(['status_id' => 300, 'profile_id' => 10]);
    createMentionPushNotification();
    Queue::assertPushed(DeliverWebPush::class, 2);
    Queue::assertNotPushed(DeliverWebPush::class, fn ($job) => $job->userId !== '1');
    expect(array_keys($this->claims))->toBe(['test:webpush:mention:1:300:1', 'test:webpush:mention:1:300:2']);
});

it('validates real local configuration without sending HTTP DNS or Web Push', function () {
    $keys = VAPID::createVapidKeys();
    config(['webpush.vapid' => ['subject' => 'mailto:admin@example.com', 'public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey']]]);
    $delivery = Mockery::mock(DeliveryService::class, [app(BoundedDnsResolver::class)])->makePartial();
    $delivery->shouldNotReceive('send');
    app()->instance(DeliveryService::class, $delivery);
    createMentionPushNotification();
    Queue::assertPushed(DeliverWebPush::class, 1);
});

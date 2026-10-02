<?php

use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Models\Follower;
use App\Models\FollowRequest;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\Status;
use App\Models\User;
use App\Services\WebPushNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/WebPushFollowFixture.php';

beforeEach(function () {
    setupWebPushFollowFixture($this);
    Queue::fake();
});
afterEach(fn () => tearDownWebPushCommentFixture());

it('queues the exact minimal follow payload without mobile opt-in', function (string $type) {
    createFollowPushNotification(['item_type' => $type === 'modern' ? Profile::class : 'App\\Profile']);
    Queue::assertPushed(DeliverWebPush::class, function ($job) {
        expect($job->payload)->toBe([
            'notification_type' => 'follow', 'title' => 'New Follower',
            'body' => '@bob followed you', 'account_id' => '20',
            'url' => '/i/web/profile/20',
        ]);
        expect($job->afterCommit)->toBeTrue();

        return true;
    });
    Queue::assertPushed(DeliverWebPush::class, 1);
    expect(array_keys($this->claims))->toBe([
        'test:webpush:follow:1:'.$this->follower->id.':'.$this->subscription->id,
    ]);
})->with(['modern', 'legacy']);

it('uses a local numeric link and normalized remote account without remote data', function () {
    DB::table('profiles')->where('id', 20)->update([
        'domain' => 'remote.example', 'username' => '@bob@remote.example',
        'remote_url' => 'https://remote.example/secret-actor-uri',
    ]);
    createFollowPushNotification();
    Queue::assertPushed(DeliverWebPush::class, function ($job) {
        expect($job->payload)->toBe([
            'notification_type' => 'follow', 'title' => 'New Follower',
            'body' => '@bob@remote.example followed you', 'account_id' => '20',
            'url' => '/i/web/profile/20',
        ]);
        expect(serialize($job))->not->toContain('https://remote.example', 'secret-actor-uri');

        return true;
    });
    Queue::assertPushed(DeliverWebPush::class, 1);
});

it('suppresses ineligible follows without a claim', function (string $change) {
    $attributes = [];
    switch ($change) {
        case 'missing relationship':
        case 'removed relationship':
            DB::table('followers')->delete();
            break;
        case 'reversed relationship':
            DB::table('followers')->update(['profile_id' => 10, 'following_id' => 20]);
            break;
        case 'wrong actor': $attributes['actor_id'] = 30;
            break;
        case 'wrong recipient': $attributes = ['profile_id' => 30, 'item_id' => 30];
            break;
        case 'item mismatch': $attributes['item_id'] = 20;
            break;
        case 'self': $attributes['actor_id'] = 10;
            break;
        case 'remote recipient':
            DB::table('profiles')->where('id', 10)->update(['domain' => 'remote.example']);
            break;
        case 'deleted actor':
        case 'deleted recipient':
            DB::table('profiles')->where('id', $change === 'deleted actor' ? 20 : 10)->update(['deleted_at' => now()]);
            break;
        case 'missing actor': $attributes['actor_id'] = 999;
            break;
        case 'missing recipient': $attributes = ['profile_id' => 999, 'item_id' => 999];
            break;
        case 'deleted user': DB::table('users')->where('id', 1)->update(['deleted_at' => now()]);
            break;
        case 'missing user': DB::table('users')->where('id', 1)->delete();
            break;
        case 'ownership mismatch': DB::table('users')->where('id', 1)->update(['profile_id' => 30]);
            break;
        case 'preference': DB::table('users')->where('id', 1)->update(['notify_follow' => false]);
            break;
        case 'subscription': $this->subscription->delete();
            break;
        case 'old notification': $attributes['created_at'] = now()->subHour();
            break;
        case 'wrong type': $attributes['item_type'] = Status::class;
            break;
        case 'unsupported action': $attributes['action'] = 'accept';
            break;
        case 'invalid username': DB::table('profiles')->where('id', 20)->update(['username' => 'https://evil.example']);
            break;
    }
    $notification = Notification::withoutEvents(fn () => createFollowPushNotification($attributes));
    WebPushNotificationService::notify($notification);
    Queue::assertNotPushed(DeliverWebPush::class);
    expect($this->claims)->toBeEmpty();
})->with([
    'missing relationship', 'removed relationship', 'reversed relationship', 'wrong actor', 'wrong recipient',
    'item mismatch', 'self', 'remote recipient', 'deleted actor', 'deleted recipient', 'missing actor',
    'missing recipient', 'deleted user', 'missing user', 'ownership mismatch', 'preference', 'subscription',
    'old notification', 'wrong type', 'unsupported action', 'invalid username',
]);

it('suppresses current recipient mute and block while retaining the relationship', function (string $filter) {
    DB::table('user_filters')->insert([
        'user_id' => 10, 'filterable_id' => 20, 'filterable_type' => Profile::class,
        'filter_type' => $filter,
    ]);
    createFollowPushNotification();
    Queue::assertNotPushed(DeliverWebPush::class);
    expect(Follower::count())->toBe(1)->and($this->claims)->toBeEmpty();
})->with(['mute', 'block']);

it('does not notify pending or rejected requests or classify follow_request', function (bool $rejected) {
    DB::table('followers')->delete();
    FollowRequest::create(['follower_id' => 20, 'following_id' => 10, 'is_rejected' => $rejected]);
    expect(Notification::count())->toBe(0);
    createFollowPushNotification();
    createFollowPushNotification(['action' => 'follow_request']);
    // Even a current relationship cannot make follow_request eligible.
    DB::table('followers')->insert(['profile_id' => 20, 'following_id' => 10, 'created_at' => now()->subMinute()]);
    createFollowPushNotification(['action' => 'follow_request']);
    Queue::assertNotPushed(DeliverWebPush::class);
    expect($this->claims)->toBeEmpty();
})->with([false, true]);

it('converges duplicate notification rows and repeated invocation', function () {
    $notification = createFollowPushNotification();
    createFollowPushNotification();
    WebPushNotificationService::notify($notification);
    Queue::assertPushed(DeliverWebPush::class, 1);
    expect(Notification::count())->toBe(2)->and($this->claims)->toHaveCount(1);
});

it('queues once per recipient subscription and never for an unrelated subscriber', function () {
    $second = User::find(1)->pushSubscriptions()->create(['endpoint' => 'https://push.example.com/second']);
    User::find(3)->pushSubscriptions()->create(['endpoint' => 'https://push.example.com/unrelated']);
    createFollowPushNotification();
    createFollowPushNotification();
    Queue::assertPushed(DeliverWebPush::class, 2);
    $ids = Queue::pushed(DeliverWebPush::class)->map(fn ($job) => $job->subscriptionId)->sort()->values()->all();
    expect($ids)->toBe([(string) $this->subscription->id, (string) $second->id]);
});

it('allows a genuine re-follow immediately with a new generation key', function () {
    createFollowPushNotification();
    DB::table('followers')->delete();
    $next = Follower::withoutEvents(fn () => Follower::create(['profile_id' => 20, 'following_id' => 10]));
    createFollowPushNotification();
    Queue::assertPushed(DeliverWebPush::class, 2);
    expect($next->id)->not->toBe($this->follower->id);
    expect(array_keys($this->claims))->toBe([
        'test:webpush:follow:1:'.$this->follower->id.':'.$this->subscription->id,
        'test:webpush:follow:1:'.$next->id.':'.$this->subscription->id,
    ]);
});

it('documents the unavoidable same-second stale replay ambiguity', function () {
    $second = now()->startOfSecond();
    $old = Notification::withoutEvents(fn () => createFollowPushNotification(['created_at' => $second]));
    DB::table('followers')->delete();
    Follower::withoutEvents(fn () => Follower::create([
        'profile_id' => 20, 'following_id' => 10, 'created_at' => $second,
    ]));
    // Equal stored timestamps do not prove which generation produced the row.
    WebPushNotificationService::notify($old);
    Queue::assertPushed(DeliverWebPush::class, 1);
});

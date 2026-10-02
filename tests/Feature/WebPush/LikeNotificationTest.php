<?php

use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Models\Like;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\Status;
use App\Models\User;
use App\Services\WebPushNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/WebPushLikeFixture.php';

beforeEach(function () {
    setupWebPushLikeFixture($this);
    Queue::fake();
});
afterEach(fn () => tearDownWebPushCommentFixture());

it('queues the exact like payload with string IDs independently of mobile opt-in', function (string $type) {
    expect(User::find(1)->notify_enabled)->toBe(0)->and($this->like->status_profile_id)->toBeNull();
    createLikePushNotification(['item_type' => $type === 'modern' ? Status::class : 'App\\Status']);
    Queue::assertPushed(DeliverWebPush::class, function ($job) {
        expect($job->payload)->toBe([
            'notification_type' => 'like', 'title' => 'New Like', 'body' => '@bob liked your post',
            'account_id' => '20', 'status_id' => '100', 'url' => '/p/alice/100',
        ])->and($job->afterCommit)->toBeTrue();

        return true;
    });
    Queue::assertPushed(DeliverWebPush::class, 1);
    expect(array_keys($this->claims))->toBe([
        'test:webpush:like:1:'.$this->like->id.':'.$this->subscription->id,
    ]);
})->with(['modern', 'legacy']);

it('normalizes a federated actor without leaking remote identity URLs', function () {
    DB::table('profiles')->where('id', 20)->update([
        'domain' => 'remote.example', 'username' => '@bob@remote.example', 'user_id' => null,
        'remote_url' => 'https://remote.example/secret-actor-uri',
    ]);
    createLikePushNotification();
    Queue::assertPushed(DeliverWebPush::class, function ($job) {
        expect($job->payload['body'])->toBe('@bob@remote.example liked your post')
            ->and($job->payload['url'])->toBe('/p/alice/100')
            ->and(serialize($job))->not->toContain('https://remote.example', 'secret-actor-uri');

        return true;
    });
    Queue::assertPushed(DeliverWebPush::class, 1);
});

it('rejects invalid likes without claiming or dispatching', function (string $change) {
    $attributes = [];
    switch ($change) {
        case 'missing like': DB::table('likes')->delete();
            break;
        case 'deleted like': DB::table('likes')->update(['deleted_at' => now()]);
            break;
        case 'wrong actor': $attributes['actor_id'] = 30;
            break;
        case 'wrong recipient': $attributes['profile_id'] = 30;
            break;
        case 'wrong status owner': DB::table('statuses')->where('id', 100)->update(['profile_id' => 30]);
            break;
        case 'wrong like status': DB::table('likes')->update(['status_id' => 200]);
            break;
        case 'missing status': DB::table('statuses')->where('id', 100)->delete();
            break;
        case 'deleted status': DB::table('statuses')->where('id', 100)->update(['deleted_at' => now()]);
            break;
        case 'missing actor': DB::table('profiles')->where('id', 20)->delete();
            break;
        case 'deleted actor': DB::table('profiles')->where('id', 20)->update(['deleted_at' => now()]);
            break;
        case 'missing recipient': DB::table('profiles')->where('id', 10)->delete();
            break;
        case 'deleted recipient': DB::table('profiles')->where('id', 10)->update(['deleted_at' => now()]);
            break;
        case 'missing user': DB::table('users')->where('id', 1)->delete();
            break;
        case 'deleted user': DB::table('users')->where('id', 1)->update(['deleted_at' => now()]);
            break;
        case 'user mismatch': DB::table('users')->where('id', 1)->update(['profile_id' => 30]);
            break;
        case 'remote recipient': DB::table('profiles')->where('id', 10)->update(['domain' => 'remote.example']);
            break;
        case 'self':
            $attributes['actor_id'] = 10;
            DB::table('likes')->update(['profile_id' => 10]);
            break;
        case 'wrong type': $attributes['item_type'] = Like::class;
            break;
        case 'legacy like type': $attributes['item_type'] = 'App\\Like';
            break;
        case 'unsupported action': $attributes['action'] = 'share';
            break;
        case 'group action': $attributes['action'] = 'group:like';
            break;
        case 'preference': DB::table('users')->where('id', 1)->update(['notify_like' => false]);
            break;
        case 'subscription': $this->subscription->delete();
            break;
        case 'old notification': $attributes['created_at'] = now()->subHour();
            break;
        case 'missing notification timestamp': $attributes['created_at'] = null;
            break;
        case 'missing like timestamp': DB::table('likes')->update(['created_at' => null]);
            break;
        case 'deleted notification': $attributes['deleted_at'] = now();
            break;
        case 'invalid actor name': DB::table('profiles')->where('id', 20)->update(['username' => '<b>bob</b>']);
            break;
    }
    $notification = Notification::withoutEvents(fn () => createLikePushNotification($attributes));
    WebPushNotificationService::notify($notification);
    Queue::assertNotPushed(DeliverWebPush::class);
    expect($this->claims)->toBeEmpty();
})->with([
    'missing like', 'deleted like', 'wrong actor', 'wrong recipient', 'wrong status owner', 'wrong like status',
    'missing status', 'deleted status', 'missing actor', 'deleted actor', 'missing recipient', 'deleted recipient',
    'missing user', 'deleted user', 'user mismatch', 'remote recipient', 'self', 'wrong type', 'legacy like type',
    'unsupported action', 'group action', 'preference', 'subscription', 'old notification',
    'missing notification timestamp', 'missing like timestamp', 'deleted notification', 'invalid actor name',
]);

it('suppresses current mute and block without changing the Like', function (string $filter) {
    DB::table('user_filters')->insert([
        'user_id' => 10, 'filterable_id' => 20, 'filterable_type' => Profile::class, 'filter_type' => $filter,
    ]);
    createLikePushNotification();
    Queue::assertNotPushed(DeliverWebPush::class);
    expect(Like::count())->toBe(1)->and($this->claims)->toBeEmpty();
})->with(['mute', 'block']);

it('sends and links ordinary public unlisted and private statuses', function (string $scope) {
    DB::table('statuses')->where('id', 100)->update(['scope' => $scope]);
    createLikePushNotification();
    Queue::assertPushed(DeliverWebPush::class, fn ($job) => $job->payload['url'] === '/p/alice/100');
    Queue::assertPushed(DeliverWebPush::class, 1);
})->with(['public', 'unlisted', 'private']);

it('rejects other scopes and group membership even with an ordinary scope', function (string $scope, ?int $group) {
    DB::table('statuses')->where('id', 100)->update(['scope' => $scope, 'group_id' => $group]);
    createLikePushNotification();
    Queue::assertNotPushed(DeliverWebPush::class);
    expect($this->claims)->toBeEmpty();
})->with([['direct', null], ['group', null], ['public', 5], ['draft', null], ['archived', null]]);

it('omits unsafe destinations without suppressing a valid event or leaking remote status URLs', function (string $change) {
    if ($change === 'username') {
        DB::table('profiles')->where('id', 10)->update(['username' => 'alice-unsafe']);
    } else {
        DB::table('statuses')->where('id', 100)->update([
            $change => $change === 'local' ? false : 'https://remote.example/secret-status-uri',
        ]);
    }
    createLikePushNotification();
    Queue::assertPushed(DeliverWebPush::class, function ($job) {
        expect($job->payload)->toBe([
            'notification_type' => 'like', 'title' => 'New Like', 'body' => '@bob liked your post',
            'account_id' => '20', 'status_id' => '100',
        ])->and(serialize($job))->not->toContain('remote.example', 'secret-status-uri');

        return true;
    });
    Queue::assertPushed(DeliverWebPush::class, 1);
})->with(['username', 'local', 'uri', 'object_url', 'url']);

it('converges duplicate Notification rows and repeated service calls', function () {
    $notification = createLikePushNotification();
    createLikePushNotification();
    WebPushNotificationService::notify($notification);
    Queue::assertPushed(DeliverWebPush::class, 1);
    expect(Notification::count())->toBe(2)->and($this->claims)->toHaveCount(1);
});

it('dispatches once per recipient subscription and never to an unrelated subscriber', function () {
    $second = User::find(1)->pushSubscriptions()->create(['endpoint' => 'https://push.example.com/second']);
    User::find(3)->pushSubscriptions()->create(['endpoint' => 'https://push.example.com/unrelated']);
    createLikePushNotification();
    createLikePushNotification();
    Queue::assertPushed(DeliverWebPush::class, 2);
    $ids = Queue::pushed(DeliverWebPush::class)->map(fn ($job) => $job->subscriptionId)->sort()->values()->all();
    expect($ids)->toBe([(string) $this->subscription->id, (string) $second->id]);
});

it('documents equal-second stale generation acceptance without claiming perfect binding', function () {
    $second = now()->startOfSecond();
    $old = Notification::withoutEvents(fn () => createLikePushNotification(['created_at' => $second]));
    Like::withoutEvents(fn () => $this->like->forceDelete());
    Like::withoutEvents(fn () => Like::create(['profile_id' => 20, 'status_id' => 100, 'created_at' => $second]));
    // The schema cannot tell whether this old Notification belongs to the new Like.
    WebPushNotificationService::notify($old);
    Queue::assertPushed(DeliverWebPush::class, 1);
});

it('rejects an old Notification against a newly created Like generation', function () {
    $old = Notification::withoutEvents(fn () => createLikePushNotification(['created_at' => now()->subHour()]));
    Like::withoutEvents(fn () => $this->like->forceDelete());
    $next = Like::withoutEvents(fn () => Like::create(['profile_id' => 20, 'status_id' => 100]));
    expect($next->id)->not->toBe($this->like->id);
    WebPushNotificationService::notify($old);
    Queue::assertNotPushed(DeliverWebPush::class);
    expect($this->claims)->toBeEmpty();
});

it('rejects a Notification removed before a direct invocation', function () {
    $notification = Notification::withoutEvents(fn () => createLikePushNotification());
    Notification::withoutEvents(fn () => $notification->forceDelete());
    WebPushNotificationService::notify($notification);
    Queue::assertNotPushed(DeliverWebPush::class);
    expect($this->claims)->toBeEmpty();
});

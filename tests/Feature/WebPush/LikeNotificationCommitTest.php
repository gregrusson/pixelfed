<?php

use App\Models\Like;
use App\Models\Notification;
use App\Models\Profile;
use App\Services\WebPushNotificationService;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/WebPushLikeFixture.php';

class RecordingLikeRedisQueue extends RedisQueue
{
    public array $enqueued = [];

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        // The real RedisQueue::push / enqueueUsing / afterCommit logic runs;
        // only the final network write is replaced with this recording sink.
        $this->enqueued[] = ['payload' => json_decode($payload, true), 'transaction_level' => DB::transactionLevel()];

        return 'recorded';
    }
}

beforeEach(function () {
    setupWebPushLikeFixture($this);
    $this->queue = new RecordingLikeRedisQueue(Mockery::mock(Factory::class), 'default', 'pushnotify', 330, null, true);
    $this->queue->setContainer($this->app);
    $this->queue->setConnectionName('redis');
    $manager = Mockery::mock(QueueManager::class);
    $manager->shouldReceive('connection')->with('redis')->andReturn($this->queue);
    Queue::swap($manager);
});
afterEach(fn () => tearDownWebPushCommentFixture());

it('only claims and enqueues after the outermost real transaction commits', function () {
    DB::beginTransaction();
    DB::beginTransaction();
    createLikePushNotification();
    expect($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty();
    DB::commit();
    expect($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty();
    DB::commit();
    expect($this->queue->enqueued)->toHaveCount(1)->and($this->claims)->toHaveCount(1)
        ->and($this->queue->enqueued[0]['transaction_level'])->toBe(0);
    $job = unserialize($this->queue->enqueued[0]['payload']['data']['command']);
    expect($job->afterCommit)->toBeTrue()->and($job->payload['notification_type'])->toBe('like');
});

it('leaves neither queue jobs nor dedupe claims on a real rollback', function () {
    DB::beginTransaction();
    createLikePushNotification();
    DB::rollBack();
    expect($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty()
        ->and(Notification::count())->toBe(0);
    createLikePushNotification();
    expect($this->queue->enqueued)->toHaveCount(1)->and($this->claims)->toHaveCount(1);
});

it('also defers a direct service call made inside a transaction', function () {
    $notification = Notification::withoutEvents(fn () => createLikePushNotification());
    DB::beginTransaction();
    WebPushNotificationService::notify($notification);
    expect($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty();
    DB::commit();
    expect($this->queue->enqueued)->toHaveCount(1)->and($this->claims)->toHaveCount(1);
});

it('reloads committed eligibility and skips a notification deleted before commit', function (string $change) {
    DB::beginTransaction();
    $notification = createLikePushNotification();
    match ($change) {
        'notification' => $notification->delete(),
        'like' => DB::table('likes')->delete(),
        'preference' => DB::table('users')->where('id', 1)->update(['notify_like' => false]),
        'filter' => DB::table('user_filters')->insert([
            'user_id' => 10, 'filterable_id' => 20, 'filterable_type' => Profile::class, 'filter_type' => 'mute',
        ]),
    };
    DB::commit();
    expect($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty();
})->with(['notification', 'like', 'preference', 'filter']);

it('discards a deferred direct service invocation on rollback', function () {
    $notification = Notification::withoutEvents(fn () => createLikePushNotification());
    DB::beginTransaction();
    WebPushNotificationService::notify($notification);
    DB::rollBack();
    expect($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty();
});

it('rolls back a newly created Like without claiming its generation', function () {
    DB::table('likes')->delete();
    DB::beginTransaction();
    Like::withoutEvents(fn () => Like::create([
        'profile_id' => 20, 'status_id' => 100,
    ]));
    createLikePushNotification();
    expect($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty();
    DB::rollBack();
    expect(Like::count())->toBe(0)->and(Notification::count())->toBe(0)
        ->and($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty();
});

it('rechecks a Like removed before a deferred direct invocation', function () {
    $notification = Notification::withoutEvents(fn () => createLikePushNotification());
    DB::beginTransaction();
    WebPushNotificationService::notify($notification);
    DB::table('likes')->delete();
    expect($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty();
    DB::commit();
    expect($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty();
});

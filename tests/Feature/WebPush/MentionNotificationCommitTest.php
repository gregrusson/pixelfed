<?php

use App\Models\Notification;
use App\Services\WebPushNotificationService;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/WebPushMentionFixture.php';

class RecordingMentionRedisQueue extends RedisQueue
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
    setupWebPushMentionFixture($this);
    $this->queue = new RecordingMentionRedisQueue(Mockery::mock(Factory::class), 'default', 'pushnotify', 330, null, true);
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
    createMentionPushNotification();
    expect($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty();
    DB::commit();
    expect($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty();
    DB::commit();
    expect($this->queue->enqueued)->toHaveCount(1)->and($this->claims)->toHaveCount(1)
        ->and($this->queue->enqueued[0]['transaction_level'])->toBe(0);
    $job = unserialize($this->queue->enqueued[0]['payload']['data']['command']);
    expect($job->afterCommit)->toBeTrue()->and($job->payload['notification_type'])->toBe('mention');
});

it('leaves neither queue jobs nor dedupe claims on a real rollback', function () {
    DB::beginTransaction();
    createMentionPushNotification();
    DB::rollBack();
    expect($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty()
        ->and(Notification::count())->toBe(0);
    createMentionPushNotification();
    expect($this->queue->enqueued)->toHaveCount(1)->and($this->claims)->toHaveCount(1);
});

it('also defers a direct service call made inside a transaction', function () {
    $notification = Notification::withoutEvents(fn () => createMentionPushNotification());
    DB::beginTransaction();
    WebPushNotificationService::notify($notification);
    expect($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty();
    DB::commit();
    expect($this->queue->enqueued)->toHaveCount(1)->and($this->claims)->toHaveCount(1);
});

it('reloads committed eligibility and skips a notification deleted before commit', function (string $change) {
    DB::beginTransaction();
    $notification = createMentionPushNotification();
    match ($change) {
        'notification' => $notification->delete(),
        'mention' => DB::table('mentions')->update(['deleted_at' => now()]),
        'preference' => DB::table('users')->where('id', 1)->update(['notify_mention' => false]),
        'status' => DB::table('statuses')->where('id', 300)->update(['deleted_at' => now()]),
    };
    DB::commit();
    expect($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty();
})->with(['notification', 'mention', 'preference', 'status']);

it('discards a deferred direct service invocation on rollback', function () {
    $notification = Notification::withoutEvents(fn () => createMentionPushNotification());
    DB::beginTransaction();
    WebPushNotificationService::notify($notification);
    DB::rollBack();
    expect($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty();
});

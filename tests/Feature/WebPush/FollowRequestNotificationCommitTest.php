<?php

use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Models\FollowRequest;
use App\Services\WebPushFollowRequestService;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/WebPushFollowRequestFixture.php';

class RecordingFollowRequestRedisQueue extends RedisQueue
{
    public array $enqueued = [];

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $this->enqueued[] = ['payload' => json_decode($payload, true), 'transaction_level' => DB::transactionLevel()];

        return 'recorded';
    }
}

beforeEach(function () {
    setupWebPushFollowRequestFixture($this);
    $this->queue = new RecordingFollowRequestRedisQueue(Mockery::mock(Factory::class), 'default', 'pushnotify', 330, null, true);
    $this->queue->setContainer($this->app);
    $this->queue->setConnectionName('redis');
    $manager = Mockery::mock(QueueManager::class);
    $manager->shouldReceive('connection')->with('redis')->andReturn($this->queue);
    Queue::swap($manager);
});
afterEach(fn () => tearDownWebPushCommentFixture());

it('waits for the outermost real commit before configuration claims and enqueue', function () {
    $this->delivery->shouldReceive('validateConfiguration')->once()->andReturnUsing(function () {
        expect(DB::transactionLevel())->toBe(0);
    });
    DB::beginTransaction();
    DB::beginTransaction();
    createPendingWebPushRequest();
    expect($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty();
    DB::commit();
    expect($this->queue->enqueued)->toBeEmpty()->and($this->claims)->toBeEmpty();
    DB::commit();
    expect($this->queue->enqueued)->toHaveCount(1)->and($this->claims)->toHaveCount(1)
        ->and($this->queue->enqueued[0]['transaction_level'])->toBe(0);
    $job = unserialize($this->queue->enqueued[0]['payload']['data']['command']);
    expect($job)->toBeInstanceOf(DeliverWebPush::class)->and($job->afterCommit)->toBeTrue()
        ->and($job->payload['notification_type'])->toBe('follow_request');
});

it('rolls back without configuration claims or jobs', function (bool $direct) {
    $this->delivery->shouldNotReceive('validateConfiguration');
    DB::beginTransaction();
    $request = $direct ? FollowRequest::withoutEvents(fn () => createPendingWebPushRequest()) : createPendingWebPushRequest();
    if ($direct) {
        WebPushFollowRequestService::notify($request);
    }
    DB::rollBack();
    expect(FollowRequest::count())->toBe(0)->and($this->claims)->toBeEmpty()->and($this->queue->enqueued)->toBeEmpty();
})->with([false, true]);

it('defers direct calls and captures scalar identity even if the supplied model changes', function () {
    $request = FollowRequest::withoutEvents(fn () => createPendingWebPushRequest());
    DB::beginTransaction();
    WebPushFollowRequestService::notify($request);
    $request->id = 999;
    $request->follower_id = 30;
    $request->following_id = 30;
    expect($this->claims)->toBeEmpty()->and($this->queue->enqueued)->toBeEmpty();
    DB::commit();
    expect($this->claims)->toHaveCount(1)->and($this->queue->enqueued)->toHaveCount(1);
});

it('reloads identity and all eligibility after commit', function (string $change, bool $direct) {
    $this->delivery->shouldNotReceive('validateConfiguration');
    DB::beginTransaction();
    $request = $direct ? FollowRequest::withoutEvents(fn () => createPendingWebPushRequest()) : createPendingWebPushRequest();
    if ($direct) {
        WebPushFollowRequestService::notify($request);
    }
    changeWebPushRequestEligibility($change, $request);
    DB::commit();
    expect($this->claims)->toBeEmpty()->and($this->queue->enqueued)->toBeEmpty();
})->with(['missing_request', 'actor_identity', 'recipient_identity', 'public_recipient', 'following', 'preference', 'mute', 'block', 'rejected', 'handled'])->with([false, true]);

it('never substitutes a replacement request for a deleted deferred generation', function () {
    DB::beginTransaction();
    $old = createPendingWebPushRequest();
    $old->delete();
    $new = createPendingWebPushRequest();
    DB::commit();
    expect($new->id)->not->toBe($old->id)->and(array_keys($this->claims))->toBe([
        'test:webpush:follow_request:1:'.$new->id.':'.$this->subscription->id,
    ])->and($this->queue->enqueued)->toHaveCount(1);
});

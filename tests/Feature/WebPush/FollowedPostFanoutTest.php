<?php

use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Jobs\PushNotificationPipeline\NewPostWebPushFanout;
use App\Jobs\PushNotificationPipeline\NewPostWebPushRecipient;
use App\Services\WebPushFollowedPostService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/WebPushFollowedPostFixture.php';
beforeEach(fn () => setupWebPushFollowedPostFixture($this));
afterEach(fn () => tearDownWebPushCommentFixture());

it('enumerates one bounded page and preserves the deadline on continuations and retries', function () {
    DB::table('followers')->delete();
    for ($i = 1; $i <= 205; $i++) {
        $pid = 1000 + $i;
        DB::table('profiles')->insert(['id' => $pid, 'user_id' => $pid, 'username' => 'follower'.$i]);
        DB::table('followers')->insert(['profile_id' => $pid, 'following_id' => 20, 'notify' => true]);
    }
    $deadline = time() + 300;
    $job = new NewPostWebPushFanout('300', '20', $deadline);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $job->handle();
    Queue::assertPushed(NewPostWebPushRecipient::class, 200);
    $continuation = Queue::pushed(NewPostWebPushFanout::class)->sole();
    expect($continuation->afterId)->toBeGreaterThan(0)->and($continuation->deadline)->toBe($deadline)
        ->and($continuation->connection)->toBe('redis')->and($continuation->queue)->toBe('feed');
    $continuation->handle();
    Queue::assertPushed(NewPostWebPushRecipient::class, 205);
    expect(collect($queries)->contains(fn ($sql) => str_contains($sql, 'limit 200')))->toBeTrue();
    $continuation->handle(); // revisits incomplete page, never relies on completed-start claim
    Queue::assertPushed(NewPostWebPushRecipient::class, 210);
});

it('skips disabled and nonlocal candidates and dedupes recipient acceptance', function () {
    DB::table('followers')->insert(['profile_id' => 30, 'following_id' => 20, 'notify' => false]);
    $job = new NewPostWebPushFanout('300', '20', $this->event['deadline']);
    $job->handle();
    $job->handle();
    Queue::assertPushed(NewPostWebPushRecipient::class, 2);
    foreach (Queue::pushed(NewPostWebPushRecipient::class) as $recipient) {
        $recipient->handle();
    }
    Queue::assertPushed(DeliverWebPush::class, 1);
});

it('does not leave recipient claims or delivery jobs across rollback', function () {
    DB::beginTransaction();
    WebPushFollowedPostService::notify($this->event, '1');
    expect($this->claims)->toBeEmpty();
    DB::rollBack();
    expect($this->claims)->toBeEmpty();
    Queue::assertNothingPushed();
});

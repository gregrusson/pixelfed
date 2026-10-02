<?php

use App\Jobs\PushNotificationPipeline\FinalizeLocalPostWebPush;
use App\Jobs\PushNotificationPipeline\NewPostWebPushFanout;
use App\Services\WebPush\LocalPublicationSupport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/WebPushFollowedPostFixture.php';
beforeEach(fn () => setupWebPushFollowedPostFixture($this));
afterEach(fn () => tearDownWebPushCommentFixture());

it('requires authorization and completion in either order, and dedupes finalization', function (bool $completionFirst) {
    if ($completionFirst) {
        LocalPublicationSupport::completed(followedPostStatus());
    } else {
        LocalPublicationSupport::authorize(followedPostStatus());
    }
    Queue::assertNotPushed(FinalizeLocalPostWebPush::class);
    if ($completionFirst) {
        LocalPublicationSupport::authorize(followedPostStatus());
    } else {
        LocalPublicationSupport::completed(followedPostStatus());
    }
    $record = LocalPublicationSupport::authorization('300', '20');
    expect($record['media_ids'])->toBe(['1'])->and($record['origin'])->toBe('local_media_publish_v1');
    LocalPublicationSupport::resume('300', '20');
    foreach (Queue::pushed(FinalizeLocalPostWebPush::class) as $queued) {
        $job = unserialize(serialize($queued));
        $job->handle();
        $job->handle();
    }
    Queue::assertPushed(NewPostWebPushFanout::class, 1);
})->with([false, true]);

it('does not authorize non-feed media structures or excluded publication types', function (array $changes) {
    DB::table('statuses')->where('id', 300)->update($changes);
    LocalPublicationSupport::authorize(followedPostStatus());
    LocalPublicationSupport::completed(followedPostStatus());
    expect(LocalPublicationSupport::authorization('300', '20'))->toBeNull();
    Queue::assertNothingPushed();
})->with([
    [['in_reply_to_id' => 100]], [['in_reply_to_profile_id' => 10]], [['reblog_of_id' => 100]], [['group_id' => 1]],
    [['scope' => 'direct']], [['scope' => 'draft']], [['scope' => 'archived']], [['visibility' => 'private']],
    [['type' => 'text']], [['type' => 'poll']], [['type' => 'story']],
]);

it('generic completion and callbacks cannot authorize imports, maintenance or remote rows', function () {
    LocalPublicationSupport::completed(followedPostStatus());
    LocalPublicationSupport::resume('300', '20');
    expect(LocalPublicationSupport::authorization('300', '20'))->toBeNull();
    Queue::assertNothingPushed();
});

it('missing or expired authorization fails closed without renewal by callbacks', function (bool $expire) {
    LocalPublicationSupport::authorize(followedPostStatus());
    $key = 'webpush:local_publication:300:20:authorization';
    if ($expire) {
        $record = LocalPublicationSupport::authorization('300', '20');
        $record['deadline'] = time() - 1;
        $this->redisValues['test:'.$key] = serialize($record);
    } else {
        Cache::store('redis')->forget($key);
    }
    LocalPublicationSupport::completed(followedPostStatus());
    LocalPublicationSupport::resume('300', '20');
    Queue::assertNothingPushed();
})->with([false, true]);

it('does not leave authorization, completion or browser jobs after rollback', function () {
    DB::beginTransaction();
    LocalPublicationSupport::authorize(followedPostStatus());
    LocalPublicationSupport::completed(followedPostStatus());
    expect($this->redisValues)->toBeEmpty();
    DB::rollBack();
    expect($this->redisValues)->toBeEmpty()->and($this->claims)->toBeEmpty();
    Queue::assertNothingPushed();
});

it('defers publication records until commit and never renews the original token or deadline', function () {
    DB::beginTransaction();
    LocalPublicationSupport::authorize(followedPostStatus());
    expect($this->redisValues)->toBeEmpty();
    DB::commit();
    $original = LocalPublicationSupport::authorization('300', '20');
    LocalPublicationSupport::authorize(followedPostStatus());
    expect(LocalPublicationSupport::authorization('300', '20'))->toBe($original);
});

it('defers a finalizer claim if invoked within a transaction that later rolls back', function () {
    LocalPublicationSupport::authorize(followedPostStatus());
    LocalPublicationSupport::completed(followedPostStatus());
    Queue::fake();
    $record = LocalPublicationSupport::authorization('300', '20');
    DB::beginTransaction();
    (new FinalizeLocalPostWebPush('300', '20', $record['token']))->handle();
    DB::rollBack();
    expect($this->claims)->toBeEmpty();
    Queue::assertNothingPushed();
});

it('does not authorize an empty attachment set or a remote media publication', function (bool $remote) {
    if ($remote) {
        DB::table('statuses')->where('id', 300)->update(['local' => false, 'uri' => 'https://remote.example/old']);
    } else {
        DB::table('media')->delete();
    }
    LocalPublicationSupport::authorize(followedPostStatus());
    LocalPublicationSupport::completed(followedPostStatus());
    expect(LocalPublicationSupport::authorization('300', '20'))->toBeNull();
    Queue::assertNothingPushed();
})->with([false, true]);

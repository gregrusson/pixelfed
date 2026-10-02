<?php

use App\Jobs\LikePipeline\LikePipeline;
use App\Jobs\LikePipeline\UnlikePipeline;
use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Models\Like;
use App\Models\Notification;
use App\Models\Status;
use App\Services\WebPushNotificationService;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/WebPushLikeFixture.php';

beforeEach(function () {
    setupWebPushLikeFixture($this);
    Queue::fake();
    isolateWebPushLikeRendering();
});
afterEach(fn () => tearDownWebPushCommentFixture());

it('creates the real like Notification and observes it through Web Push while converging retries', function () {
    (new LikePipeline($this->like))->handle();
    (new LikePipeline($this->like->fresh()))->handle();
    $notification = Notification::sole();
    expect($notification->action)->toBe('like')
        ->and((string) $notification->actor_id)->toBe('20')
        ->and((string) $notification->profile_id)->toBe('10')
        ->and((string) $notification->item_id)->toBe('100')
        ->and($notification->item_type)->toBe(Status::class);
    WebPushNotificationService::notify($notification);
    Queue::assertPushed(DeliverWebPush::class, 1);
    expect($this->claims)->toHaveCount(1);
});

it('allows a new generation only after normal UnlikePipeline deletion completes', function () {
    (new LikePipeline($this->like))->handle();
    $oldNotification = Notification::sole();
    // Until the queued unlike actually deletes the row, firstOrCreate reuses it.
    $beforeDeletion = Like::firstOrCreate(['profile_id' => 20, 'status_id' => 100]);
    expect($beforeDeletion->id)->toBe($this->like->id)->and($beforeDeletion->wasRecentlyCreated)->toBeFalse();
    (new UnlikePipeline($this->like->fresh()))->handle();
    expect(Like::withTrashed()->count())->toBe(0)->and(Notification::withTrashed()->count())->toBe(0);
    WebPushNotificationService::notify($oldNotification);
    Queue::assertPushed(DeliverWebPush::class, 1);
    expect($this->claims)->toHaveCount(1);

    $next = Like::create(['profile_id' => 20, 'status_id' => 100]);
    (new LikePipeline($next))->handle();
    expect($next->id)->not->toBe($this->like->id);
    Queue::assertPushed(DeliverWebPush::class, 2);
    expect(array_keys($this->claims))->toBe([
        'test:webpush:like:1:'.$this->like->id.':'.$this->subscription->id,
        'test:webpush:like:1:'.$next->id.':'.$this->subscription->id,
    ]);
});

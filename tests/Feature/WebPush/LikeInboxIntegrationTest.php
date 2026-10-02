<?php

use App\Jobs\LikePipeline\LikePipeline;
use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Models\Like;
use App\Models\Notification;
use App\Models\Profile;
use App\Services\WebPushNotificationService;
use App\Util\ActivityPub\Helpers;
use App\Util\ActivityPub\Inbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/WebPushLikeFixture.php';

class WebPushLikeTestInbox extends Inbox
{
    // Signatures and actor fetching are separate concerns. The actual verb
    // validator, Like/Undo handlers and local Status resolution still execute.
    public function validateAndFetchActor(string $actorUrl): ?Profile
    {
        return Profile::whereRemoteUrl($actorUrl)->first();
    }

    public function isDomainBlocked(int $profileId, ?string $domain): bool
    {
        return false;
    }

    public function isUserBlocked(int $profileId, int $actorId): bool
    {
        return false;
    }
}

function inboundWebPushLike(string $type): WebPushLikeTestInbox
{
    $like = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => 'https://remote.example/activities/like-1', 'type' => 'Like',
        'actor' => 'https://remote.example/users/bob', 'object' => 'https://pixelfed.example/p/alice/100',
        'to' => ['https://pixelfed.example/users/alice'],
    ];
    $payload = $type === 'Like' ? $like : [
        'id' => 'https://remote.example/activities/undo-1', 'type' => 'Undo',
        'actor' => $like['actor'], 'object' => $like,
    ];

    return new WebPushLikeTestInbox([], null, $payload);
}

beforeEach(function () {
    setupWebPushLikeFixture($this);
    Queue::fake();
    isolateWebPushLikeRendering();
    config(['app.url' => 'https://pixelfed.example', 'pixelfed.domain.app' => 'pixelfed.example',
        'pixelfed.domain.ap' => 'pixelfed.example', 'pixelfed.domain.admin' => 'pixelfed.example']);
    // Preload federation URL validation's DNS cache; no resolver is contacted.
    Cache::put('helpers:url:public-ips:v2:'.hash('xxh128', 'remote.example'), [
        'state' => Helpers::URL_OK, 'ips' => ['8.8.8.8'],
    ]);
    DB::table('likes')->delete();
    DB::table('profiles')->where('id', 20)->update([
        'domain' => 'remote.example', 'username' => '@bob@remote.example', 'user_id' => null,
        'remote_url' => 'https://remote.example/users/bob',
    ]);
});
afterEach(fn () => tearDownWebPushCommentFixture());

it('handles federated Like and replay through the actual notification observer path', function () {
    inboundWebPushLike('Like')->handle();
    inboundWebPushLike('Like')->handle();
    expect(Like::count())->toBe(1)->and(Like::sole()->status_profile_id)->toBeNull();
    Queue::assertPushed(LikePipeline::class, 1);
    foreach (Queue::pushed(LikePipeline::class) as $job) {
        $job->handle();
    }
    expect(Notification::sole()->action)->toBe('like');
    (new LikePipeline(Like::sole()))->handle();
    Queue::assertPushed(DeliverWebPush::class, function ($job) {
        expect($job->payload)->toBe([
            'notification_type' => 'like', 'title' => 'New Like', 'body' => '@bob@remote.example liked your post',
            'account_id' => '20', 'status_id' => '100', 'url' => '/p/alice/100',
        ])->and(serialize($job))->not->toContain('https://remote.example');

        return true;
    });
    Queue::assertPushed(DeliverWebPush::class, 1);
});

it('hard-deletes the Like and Notification through actual federated Undo and rejects stale orchestration', function () {
    inboundWebPushLike('Like')->handle();
    (new LikePipeline(Like::sole()))->handle();
    $old = Notification::sole();
    $firstLikeId = Like::sole()->id;
    inboundWebPushLike('Undo')->handle();
    expect(Like::withTrashed()->count())->toBe(0)->and(Notification::withTrashed()->count())->toBe(0);
    WebPushNotificationService::notify($old);
    Queue::assertPushed(DeliverWebPush::class, 1);
    expect($this->claims)->toHaveCount(1);
    inboundWebPushLike('Like')->handle();
    $next = Like::sole();
    expect($next->id)->not->toBe($firstLikeId);
    (new LikePipeline($next))->handle();
    Queue::assertPushed(DeliverWebPush::class, 2);
    expect($this->claims)->toHaveCount(2);
});

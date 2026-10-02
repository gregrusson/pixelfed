<?php

use App\Jobs\MentionPipeline\MentionPipeline;
use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Jobs\StatusPipeline\StatusEntityLexer;
use App\Jobs\StatusPipeline\StatusTagsPipeline;
use App\Models\Mention;
use App\Models\Notification;
use App\Models\Status;
use App\Services\AccountService;
use App\Services\RelationshipService;
use App\Services\StatusService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

require_once __DIR__.'/../../Support/WebPushMentionFixture.php';

beforeEach(function () {
    setupWebPushMentionFixture($this);
    Queue::fake();
    DB::table('mentions')->delete();
    // Keep unrelated notification rendering/cache services isolated. The lexer,
    // tag parser, MentionPipeline, Notification creation, observer, visibility,
    // Web Push service and Redis claim all execute their real code.
    foreach ([10, 20, 30] as $id) {
        Cache::put(AccountService::CACHE_KEY.$id, ['id' => (string) $id]);
    }
    Cache::put(RelationshipService::CACHE_KEY.'a_20:t_10', ['id' => '10']);
    $realCache = Cache::getFacadeRoot();
    $cache = Mockery::mock($realCache);
    $cache->shouldReceive('remember')->byDefault()->andReturnUsing(
        fn ($key, $ttl, $callback) => $realCache->remember($key, $ttl, $callback)
    );
    Cache::swap($cache);
    $cache->shouldReceive('remember')
        ->with(StatusService::key(300, false), 21600, Mockery::type(Closure::class))
        ->andReturn(['id' => '300']);
    $cache->shouldReceive('remember')
        ->with(StatusService::key(300, true), 21600, Mockery::type(Closure::class))
        ->andReturn(['id' => '300']);
    Redis::shouldReceive('zrevrange')->andReturn([]);
    Redis::shouldReceive('zadd', 'expire')->andReturn(1);
});
afterEach(fn () => tearDownWebPushCommentFixture());

it('extracts a local caption mention and creates a real internal notification through the observer', function () {
    DB::table('statuses')->where('id', 300)->update(['caption' => 'Hello @alice @alice']);
    $lexer = new StatusEntityLexer(Status::find(300));
    $lexer->parseEntities();
    expect(Mention::whereStatusId(300)->whereProfileId(10)->count())->toBe(1);
    Queue::assertPushed(MentionPipeline::class, 1);
    foreach (Queue::pushed(MentionPipeline::class) as $entry) {
        $entry->handle();
    }
    $notification = Notification::sole();
    expect($notification->action)->toBe('mention')->and((string) $notification->profile_id)->toBe('10')
        ->and((string) $notification->actor_id)->toBe('20')->and((string) $notification->item_id)->toBe('300')
        ->and($notification->item_type)->toBe(Status::class);
    Queue::assertPushed(DeliverWebPush::class, fn ($job) => $job->payload['notification_type'] === 'mention');
    Queue::assertPushed(DeliverWebPush::class, 1);
});

it('processes federated Mention tags and repeated tag imports through the real mention and observer path', function () {
    config(['app.url' => 'https://pixelfed.example', 'pixelfed.domain.app' => 'pixelfed.example']);
    DB::table('profiles')->where('id', 20)->update(['domain' => 'remote.example', 'username' => '@bob@remote.example', 'user_id' => null]);
    DB::table('statuses')->where('id', 300)->update(['local' => false, 'uri' => 'https://remote.example/secret-uri']);
    $tags = ['tag' => [
        ['type' => 'Mention', 'href' => 'https://pixelfed.example/users/alice', 'name' => '@alice'],
        ['type' => 'Mention', 'href' => 'https://pixelfed.example/users/alice', 'name' => '@alice'],
    ]];
    (new StatusTagsPipeline($tags, Status::find(300)))->handle();
    (new StatusTagsPipeline($tags, Status::find(300)))->handle();
    expect(Mention::whereStatusId(300)->whereProfileId(10)->count())->toBe(4);
    Queue::assertPushed(MentionPipeline::class, 4);
    foreach (Queue::pushed(MentionPipeline::class) as $entry) {
        $entry->handle();
    }
    expect(Notification::count())->toBe(1)->and(Notification::sole()->action)->toBe('mention');
    Queue::assertPushed(DeliverWebPush::class, function ($job) {
        expect($job->payload)->toBe([
            'notification_type' => 'mention', 'title' => 'New Mention',
            'body' => '@bob@remote.example mentioned you', 'account_id' => '20', 'status_id' => '300',
        ]);

        return true;
    });
    Queue::assertPushed(DeliverWebPush::class, 1);
});

<?php

use App\Jobs\HomeFeedPipeline\FeedInsertRemotePipeline;
use App\Jobs\PushNotificationPipeline\NewPostWebPushFanout;
use App\Models\Profile;
use App\Models\Status;
use App\Services\ConfigCacheService;
use App\Util\ActivityPub\Helpers;
use App\Util\ActivityPub\Inbox;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../../Support/WebPushFollowedPostFixture.php';

class FollowedPostTestInbox extends Inbox
{
    // Actor fetching/signature verification are covered separately. Exercise
    // the actual Create handler, authorship checks, storage and post-processing.
    public function validateAndFetchActor(string $url): ?Profile
    {
        return Profile::whereRemoteUrl($url)->first();
    }

    public function signingProfile(): ?Profile
    {
        return Profile::find(20);
    }
}

beforeEach(function () {
    setupWebPushFollowedPostFixture($this);
    config(['instance.enable_cc' => false, 'pixelfed.media_types' => 'image/jpeg,image/png,video/mp4', 'instance.timeline.network.cached' => false,
        'pixelfed.domain.app' => 'pixelfed.example', 'pixelfed.domain.ap' => 'pixelfed.example']);
    Schema::table('profiles', function (Blueprint $table) {
        $table->boolean('unlisted')->default(false);
        $table->timestamp('last_fetched_at')->nullable();
    });
    Schema::table('statuses', function (Blueprint $table) {
        $table->string('type')->nullable()->default(null)->change();
        $table->boolean('is_nsfw')->default(false);
        $table->string('cw_summary')->nullable();
    });
    Schema::table('media', function (Blueprint $table) {
        $table->string('remote_url')->nullable();
        $table->integer('version')->nullable();
        $table->integer('order')->default(0);
        $table->string('caption')->nullable();
        $table->string('blurhash')->nullable();
    });
    makeWebPushRequestActorRemote();
    DB::table('profiles')->where('id', 20)->update(['followers_count' => 1, 'last_fetched_at' => now()]);
    Cache::put('helpers:url:public-ips:v2:'.hash('xxh128', 'remote.example'), ['state' => Helpers::URL_OK, 'ips' => ['8.8.8.8']]);
    Cache::put(ConfigCacheService::CACHE_KEY.'pixelfed.media_types', 'image/jpeg,image/png,video/mp4');
    Redis::shouldReceive('zadd', 'zcount', 'expire', 'del')->andReturn(1);
});
afterEach(fn () => tearDownWebPushCommentFixture());

function followedPostRemoteNote(array $overrides = []): array
{
    return array_merge([
        'id' => 'https://remote.example/users/bob/statuses/new', 'url' => 'https://remote.example/@bob/new',
        'type' => 'Note', 'attributedTo' => 'https://remote.example/users/bob',
        'published' => now()->subMinute()->toAtomString(), 'content' => '<p>remote caption</p>',
        'to' => ['https://www.w3.org/ns/activitystreams#Public'], 'cc' => ['https://remote.example/users/bob/followers'],
        'attachment' => [['type' => 'Document', 'mediaType' => 'image/jpeg', 'url' => 'https://remote.example/media/one.jpg']],
    ], $overrides);
}

function followedPostCreate(array $note): FollowedPostTestInbox
{
    return new FollowedPostTestInbox([], null, ['id' => $note['id'].'/activity', 'type' => 'Create',
        'actor' => 'https://remote.example/users/bob', 'object' => $note]);
}

it('fanouts only newly stored trusted media Creates and leaves feed insertion unchanged', function (array $mimes, string $type) {
    $note = followedPostRemoteNote(['attachment' => array_map(fn ($mime, $i) => ['type' => 'Document', 'mediaType' => $mime, 'url' => 'https://remote.example/media/'.$i], $mimes, array_keys($mimes))]);
    followedPostCreate($note)->handle();
    followedPostCreate($note)->handle();
    $status = Status::whereObjectUrl($note['id'])->sole();
    expect($status->type)->toBe($type)->and((bool) $status->local)->toBeFalse();
    Queue::assertPushed(NewPostWebPushFanout::class, 1);
    Queue::assertPushed(FeedInsertRemotePipeline::class, 1);
})->with([
    [['image/jpeg'], 'photo'], [['image/jpeg', 'image/png'], 'photo:album'],
    [['video/mp4'], 'video'], [['video/mp4', 'video/mp4'], 'video:album'], [['image/jpeg', 'video/mp4'], 'photo:video:album'],
]);

it('does not manufacture an event from generic fetched or previously stored objects', function () {
    $note = followedPostRemoteNote();
    Helpers::storeStatus($note['url'], Profile::find(20), $note);
    Queue::assertNotPushed(NewPostWebPushFanout::class);
    followedPostCreate($note)->handle();
    Queue::assertNotPushed(NewPostWebPushFanout::class);
});

it('keeps URL/context fetching outside trusted Create provenance even for a fresh object', function () {
    $note = followedPostRemoteNote(['@context' => 'https://www.w3.org/ns/activitystreams']);
    Cache::put(Helpers::fetchCacheKey($note['id']), $note);
    $status = Helpers::statusFirstOrFetch($note['id']);
    expect($status)->not->toBeNull();
    Queue::assertNotPushed(NewPostWebPushFanout::class);
    followedPostCreate($note)->handle();
    Queue::assertNotPushed(NewPostWebPushFanout::class);
});

it('applies age bounds only to browser fanout while keeping normal ingestion', function (string $published) {
    $note = followedPostRemoteNote(['published' => $published]);
    followedPostCreate($note)->handle();
    expect(Status::whereObjectUrl($note['id'])->exists())->toBeTrue();
    Queue::assertPushed(FeedInsertRemotePipeline::class, 1);
    Queue::assertNotPushed(NewPostWebPushFanout::class);
})->with([fn () => now()->subHours(25)->toAtomString(), fn () => now()->addMinutes(6)->toAtomString()]);

it('rejects untrusted object fallback as new-post provenance', function () {
    $note = followedPostRemoteNote(['attributedTo' => 'https://remote.example/users/other']);
    Cache::put(Helpers::fetchCacheKey($note['id']), false);
    followedPostCreate($note)->handle();
    Queue::assertNotPushed(NewPostWebPushFanout::class);
    expect(Status::whereObjectUrl($note['id'])->exists())->toBeFalse();
});

it('supports followers-only Creates and defers browser fanout until commit', function (bool $commit) {
    $note = followedPostRemoteNote(['to' => ['https://remote.example/users/bob/followers'], 'cc' => []]);
    DB::beginTransaction();
    followedPostCreate($note)->handle();
    expect(Status::whereObjectUrl($note['id'])->sole()->scope)->toBe('private');
    Queue::assertNotPushed(NewPostWebPushFanout::class);
    if ($commit) {
        DB::commit();
    } else {
        DB::rollBack();
    }
    Queue::assertPushed(NewPostWebPushFanout::class, $commit ? 1 : 0);
    expect($this->claims)->toBeEmpty();
})->with([false, true]);

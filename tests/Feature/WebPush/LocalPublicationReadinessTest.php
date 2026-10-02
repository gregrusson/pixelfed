<?php

use App\Jobs\PushNotificationPipeline\FinalizeLocalPostWebPush;
use App\Jobs\PushNotificationPipeline\NewPostWebPushFanout;
use App\Models\Media;
use App\Models\Status;
use App\Services\ConfigCacheService;
use App\Services\WebPush\LocalPublicationSupport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/WebPushFollowedPostFixture.php';
beforeEach(fn () => setupWebPushFollowedPostFixture($this));
afterEach(fn () => tearDownWebPushCommentFixture());

function finalizeFixturePost(): void
{
    LocalPublicationSupport::authorize(followedPostStatus());
    LocalPublicationSupport::completed(followedPostStatus());
    foreach (Queue::pushed(FinalizeLocalPostWebPush::class) as $queued) {
        $queued->handle();
    }
}

it('permits each supported composition without optional processing fields', function (string $type, array $mimes) {
    DB::table('media')->delete();
    foreach ($mimes as $index => $mime) {
        $path = 'public/part'.$index;
        Storage::disk('local')->put($path, 'primary');
        DB::table('media')->insert(['id' => $index + 1, 'status_id' => 300, 'profile_id' => 20, 'user_id' => 2, 'media_path' => $path, 'mime' => $mime]);
    }
    DB::table('statuses')->where('id', 300)->update(['type' => $type]);
    finalizeFixturePost();
    Queue::assertPushed(NewPostWebPushFanout::class, 1);
    expect(Media::whereNotNull('processed_at')->count())->toBe(0);
})->with([
    ['photo', ['image/jpeg']], ['photo:album', ['image/jpeg', 'image/png']],
    ['video', ['video/mp4']], ['video:album', ['video/mp4', 'video/mp4']],
    ['photo:video:album', ['image/jpeg', 'video/mp4']],
]);

it('suppresses missing or changed required attachment generations', function (string $change) {
    LocalPublicationSupport::authorize(followedPostStatus());
    match ($change) {
        'deleted' => DB::table('media')->where('id', 1)->update(['deleted_at' => now()]),
        'missing' => DB::table('media')->delete(),
        'detached' => DB::table('media')->where('id', 1)->update(['status_id' => 999]),
        'remote' => DB::table('media')->where('id', 1)->update(['remote_media' => true]),
        'owner' => DB::table('media')->where('id', 1)->update(['profile_id' => 30]),
        'composition' => DB::table('media')->where('id', 1)->update(['mime' => 'video/mp4']),
        'empty_path' => DB::table('media')->where('id', 1)->update(['media_path' => '']),
        'missing_file' => Storage::disk('local')->delete('public/photo.jpg'),
        'default_s3' => config(['filesystems.default' => 's3']),
        'status_deleted' => DB::table('statuses')->where('id', 300)->update(['deleted_at' => now()]),
    };
    LocalPublicationSupport::completed(followedPostStatus() ?? new Status(['id' => 300, 'profile_id' => 20]));
    foreach (Queue::pushed(FinalizeLocalPostWebPush::class) as $queued) {
        $queued->handle();
    }
    Queue::assertNotPushed(NewPostWebPushFanout::class);
})->with(['deleted', 'missing', 'detached', 'remote', 'owner', 'composition', 'empty_path', 'missing_file', 'default_s3', 'status_deleted']);

it('keeps slow cloud blocked until every required cdn URL exists, then resumes', function () {
    cloudPublicationFixture(false);
    finalizeFixturePost();
    Queue::assertNotPushed(NewPostWebPushFanout::class);
    DB::table('media')->where('id', 1)->update(['cdn_url' => 'https://cdn.example/photo.jpg']);
    LocalPublicationSupport::resume('300', '20');
    foreach (Queue::pushed(FinalizeLocalPostWebPush::class) as $queued) {
        $queued->handle();
    }
    Queue::assertPushed(NewPostWebPushFanout::class, 1);
});

function cloudPublicationFixture(bool $fast): void
{
    config(['pixelfed.cloud_storage' => true, 'pixelfed.media_fast_process' => $fast]);
    Cache::put(ConfigCacheService::CACHE_KEY.'pixelfed.cloud_storage', true);
}

it('allows fast cloud with a usable local original before replication', function () {
    cloudPublicationFixture(true);
    finalizeFixturePost();
    Queue::assertPushed(NewPostWebPushFanout::class, 1);
});

it('does not make failed optional image derivatives a primary publication gate', function () {
    DB::table('media')->where('id', 1)->update(['processed_at' => now(), 'thumbnail_path' => 'public/failed-thumbnail.jpg']);
    expect(Storage::disk('local')->exists('public/failed-thumbnail.jpg'))->toBeFalse();
    finalizeFixturePost();
    Queue::assertPushed(NewPostWebPushFanout::class, 1);
});

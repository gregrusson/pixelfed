<?php

use App\Http\Controllers\Api\ApiV1Controller;
use App\Http\Controllers\Api\ApiV1Dot1Controller;
use App\Http\Controllers\ComposeController;
use App\Jobs\HomeFeedPipeline\FeedInsertPipeline;
use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Jobs\PushNotificationPipeline\FinalizeLocalPostWebPush;
use App\Jobs\PushNotificationPipeline\FollowPushNotifyPipeline;
use App\Jobs\PushNotificationPipeline\LikePushNotifyPipeline;
use App\Jobs\PushNotificationPipeline\MentionPushNotifyPipeline;
use App\Jobs\PushNotificationPipeline\NewPostWebPushFanout;
use App\Jobs\PushNotificationPipeline\NewPostWebPushRecipient;
use App\Jobs\StatusPipeline\NewStatusPipeline;
use App\Jobs\StatusPipeline\StatusEntityLexer;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Status;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AdminShadowFilterService;
use App\Services\MediaStorageService;
use App\Services\WebPush\LocalPublicationSupport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\AccessToken;

require_once __DIR__.'/../../Support/WebPushFollowedPostFixture.php';
beforeEach(function () {
    setupWebPushFollowedPostFixture($this);
    config(['instance.enable_cc' => false, 'exp.cached_home_timeline' => true, 'pixelfed.bouncer.enabled' => false, 'federation.activitypub.enabled' => false, 'exp.emc' => false]);
    Schema::table('users', fn (Blueprint $table) => $table->timestamp('last_active_at')->nullable());
    Schema::table('profiles', function (Blueprint $table) {
        $table->integer('status_count')->default(0);
        $table->boolean('cw')->default(false);
    });
    Schema::table('statuses', function (Blueprint $table) {
        $table->boolean('is_nsfw')->default(false);
        $table->string('cw_summary')->nullable();
        $table->integer('quote_policy')->nullable();
    });
    Schema::table('media', function (Blueprint $table) {
        $table->integer('order')->default(0);
        $table->boolean('is_nsfw')->default(false);
        $table->string('filter_class')->nullable();
        $table->string('license')->nullable();
        $table->string('caption')->nullable();
    });
    Schema::create('dm_message_media', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('media_id');
    });
    DB::table('users')->where('id', 2)->update(['last_active_at' => now()]);
    Redis::shouldReceive('del', 'zadd', 'expire')->andReturn(1);
    Redis::shouldReceive('zcount', 'zcard', 'zremrangebyrank')->andReturn(1);
    Cache::put(AdminShadowFilterService::CACHE_KEY.'list:hide_from_public_feeds', []);
    $real = Cache::getFacadeRoot();
    $cache = Mockery::mock($real);
    $cache->shouldReceive('remember')->andReturnUsing(function ($key, $ttl, $callback) use ($real) {
        if (str_starts_with($key, 'pf:services:status:')) {
            return ['id' => '300', 'account' => ['id' => '20'], 'url' => 'https://pixelfed.example/p/bob/300', 'pf_type' => 'photo', 'reply_count' => 0];
        }
        if (str_starts_with($key, AccountService::CACHE_KEY)) {
            return ['id' => '20', 'username' => 'bob'];
        }

        return $real->remember($key, $ttl, $callback);
    });
    Cache::swap($cache);
});
afterEach(fn () => tearDownWebPushCommentFixture());

it('authorizes actual API media publication from persisted attachment rows', function (array $mimes, string $type) {
    DB::table('media')->delete();
    foreach ($mimes as $i => $mime) {
        DB::table('media')->insert(['id' => $i + 1, 'status_id' => 0, 'profile_id' => 20, 'user_id' => 2, 'media_path' => 'public/part'.$i, 'mime' => $mime]);
        Storage::disk('local')->put('public/part'.$i, 'usable primary');
    }
    // Uploads are unattached before publication.
    Schema::table('media', fn (Blueprint $table) => $table->unsignedBigInteger('status_id')->nullable()->change());
    DB::table('media')->update(['status_id' => null]);
    $user = User::find(2)->withAccessToken(new AccessToken(['oauth_scopes' => ['write']]));
    $request = Request::create('/api/v1/statuses', 'POST', ['media_ids' => range(1, count($mimes)), 'visibility' => 'public', '_pe' => true]);
    $request->setUserResolver(fn () => $user);
    (new ApiV1Controller)->statusCreate($request);
    $status = Status::where('id', '!=', 300)->sole();
    expect($status->type)->toBe($type);
    $record = LocalPublicationSupport::authorization((string) $status->id, '20');
    expect($record['media_ids'])->toBe(array_map('strval', range(1, count($mimes))));
    Queue::assertPushed(NewStatusPipeline::class, 1);
    Queue::assertNotPushed(FinalizeLocalPostWebPush::class);
    foreach (Queue::pushed(NewStatusPipeline::class) as $job) {
        $job->handle();
    }
    foreach (Queue::pushed(StatusEntityLexer::class) as $job) {
        $job->handle();
    }
    foreach (Queue::pushed(FinalizeLocalPostWebPush::class) as $job) {
        $job->handle();
    }
    foreach (Queue::pushed(NewPostWebPushFanout::class) as $job) {
        $job->handle();
    }
    foreach (Queue::pushed(NewPostWebPushRecipient::class) as $job) {
        $job->handle();
    }
    Queue::assertPushed(DeliverWebPush::class, fn ($job) => $job->payload === [
        'notification_type' => 'new_post', 'title' => 'New Post', 'body' => '@bob posted something new',
        'account_id' => '20', 'status_id' => (string) $status->id, 'url' => '/i/web/post/'.$status->id,
    ]);
    expect(Notification::count())->toBe(0);
    Queue::assertNotPushed(MentionPushNotifyPipeline::class);
    Queue::assertNotPushed(FollowPushNotifyPipeline::class);
    Queue::assertNotPushed(LikePushNotifyPipeline::class);
})->with([
    [['image/jpeg'], 'photo'], [['image/jpeg', 'image/png'], 'photo:album'],
    [['video/mp4'], 'video'], [['video/mp4', 'video/mp4'], 'video:album'],
    [['image/jpeg', 'video/mp4'], 'photo:video:album'],
]);

it('uses the actual lexer convergence and permits the callback pipeline winning the lock', function (bool $callbackWins) {
    if (! $callbackWins) {
        LocalPublicationSupport::authorize(followedPostStatus());
    }
    (new NewStatusPipeline(followedPostStatus()))->handle();
    Queue::assertPushed(StatusEntityLexer::class, 1);
    foreach (Queue::pushed(StatusEntityLexer::class) as $lexer) {
        $lexer->handle();
    }
    Queue::assertPushed(FeedInsertPipeline::class, 1);
    if ($callbackWins) {
        Queue::assertNotPushed(FinalizeLocalPostWebPush::class);
        LocalPublicationSupport::authorize(followedPostStatus());
    }
    (new NewStatusPipeline(followedPostStatus()))->handle(); // competing original/callback loses existing lock
    Queue::assertPushed(StatusEntityLexer::class, 1);
    Queue::assertPushed(FinalizeLocalPostWebPush::class, 1);
})->with([false, true]);

it('authorizes the existing browser compose media endpoints', function (array $mimes, string $type) {
    Schema::table('profiles', fn (Blueprint $table) => $table->boolean('unlisted')->default(false));
    Schema::table('media', fn (Blueprint $table) => $table->unsignedBigInteger('status_id')->nullable()->change());
    DB::table('media')->delete();
    foreach ($mimes as $i => $mime) {
        DB::table('media')->insert(['id' => $i + 1, 'profile_id' => 20, 'user_id' => 2, 'media_path' => 'public/part'.$i, 'mime' => $mime]);
    }
    $request = Request::create('/api/compose/v0/publish', 'POST', ['media' => array_map(fn ($id) => ['id' => $id, 'filter_class' => null], range(1, count($mimes))), 'visibility' => 'public', 'cw' => false, 'tagged' => []]);
    $request->setUserResolver(fn () => User::find(2));
    (new ComposeController)->store($request);
    $status = Status::where('id', '!=', 300)->sole();
    expect($status->type)->toBe($type)
        ->and(LocalPublicationSupport::authorization((string) $status->id, '20')['media_ids'])->toBe(array_map('strval', range(1, count($mimes))));
    Queue::assertPushed(NewStatusPipeline::class, 1);
})->with([[['image/jpeg'], 'photo'], [['image/jpeg', 'image/png'], 'photo:album'], [['video/mp4'], 'video']]);

it('authorizes the mobile single-media publication endpoint using its persisted upload', function (string $mime) {
    config(['pixelfed.media_types' => 'image/jpeg,image/png,video/mp4', 'pixelfed.enforce_account_limit' => false]);
    Schema::table('profiles', fn (Blueprint $table) => $table->boolean('unlisted')->default(false));
    Schema::table('users', function (Blueprint $table) {
        $table->integer('storage_used')->default(0);
        $table->timestamp('storage_used_updated_at')->nullable();
        $table->timestamps();
    });
    Schema::table('media', function (Blueprint $table) {
        $table->string('original_sha256')->nullable();
        $table->integer('size')->nullable();
    });
    Schema::create('media_blocklists', function (Blueprint $table) {
        $table->id();
        $table->string('sha256');
        $table->boolean('active');
    });
    Schema::create('user_settings', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->json('compose_settings')->nullable();
        $table->timestamps();
    });
    Schema::create('default_domain_blocks', function (Blueprint $table) {
        $table->id();
        $table->string('domain');
    });
    $file = $mime === 'image/jpeg' ? UploadedFile::fake()->image('post.jpg') : UploadedFile::fake()->create('post.mp4', 4, $mime);
    $request = Request::create('/api/v1.1/status', 'POST', ['visibility' => 'public'], [], ['file' => $file]);
    $request->setUserResolver(fn () => User::find(2)->withAccessToken(new AccessToken(['oauth_scopes' => ['write']])));
    (new ApiV1Dot1Controller)->statusCreate($request);
    $status = Status::where('id', '!=', 300)->sole();
    expect($status->type)->toBe($mime === 'image/jpeg' ? 'photo' : 'video')->and(LocalPublicationSupport::authorization((string) $status->id, '20')['media_ids'])->toBe([(string) $status->media()->sole()->getKey()]);
    Queue::assertPushed(NewStatusPipeline::class, 1);
})->with(['image/jpeg', 'video/mp4']);

it('lets actual slow-cloud storage callbacks resolve existing authorization without creating it', function (bool $authorized) {
    config(['pixelfed.cloud_storage' => true, 'pixelfed.media_fast_process' => false,
        'filesystems.cloud' => 's3', 'media.storage.remote.resilient_mode' => false, 'media.delete_local_after_cloud' => false]);
    Storage::fake('s3');
    $name = 'followed-post-test-'.bin2hex(random_bytes(8));
    $path = storage_path('app/'.$name);
    file_put_contents($path, 'usable staged primary');
    DB::table('media')->where('id', 1)->update(['media_path' => $name]);
    try {
        if ($authorized) {
            LocalPublicationSupport::authorize(followedPostStatus());
        }
        MediaStorageService::store(Media::find(1));
        expect(Media::find(1)->cdn_url)->not->toBeEmpty();
        Queue::assertPushed(NewStatusPipeline::class, 1);
        foreach (Queue::pushed(NewStatusPipeline::class) as $job) {
            $job->handle();
        }
        foreach (Queue::pushed(StatusEntityLexer::class) as $job) {
            $job->handle();
        }
        Queue::assertPushed(FinalizeLocalPostWebPush::class, $authorized ? 1 : 0);
        foreach (Queue::pushed(FinalizeLocalPostWebPush::class) as $job) {
            $job->handle();
        }
        Queue::assertPushed(NewPostWebPushFanout::class, $authorized ? 1 : 0);
        expect(LocalPublicationSupport::authorization('300', '20') !== null)->toBe($authorized);
    } finally {
        unlink($path);
    }
})->with([false, true]);

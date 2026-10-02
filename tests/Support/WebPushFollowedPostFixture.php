<?php

use App\Models\Follower;
use App\Models\Status;
use App\Services\AccountService;
use App\Services\ConfigCacheService;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/WebPushFollowRequestFixture.php';

function setupWebPushFollowedPostFixture($test): void
{
    setupWebPushFollowRequestFixture($test);
    Schema::table('users', fn (Blueprint $table) => $table->string('status')->nullable());
    Schema::table('profiles', fn (Blueprint $table) => $table->boolean('no_autolink')->default(true));
    Schema::table('followers', fn (Blueprint $table) => $table->boolean('notify')->default(false));
    Schema::table('statuses', function (Blueprint $table) {
        $table->string('type')->default('photo');
        $table->string('visibility')->default('public');
        $table->boolean('local')->default(true);
        $table->unsignedBigInteger('reblog_of_id')->nullable();
        $table->text('rendered')->nullable();
        $table->string('url')->nullable();
        $table->string('object_url')->nullable()->unique();
        $table->timestamp('edited_at')->nullable();
        $table->timestamps();
    });
    Schema::create('media', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('status_id');
        $table->unsignedBigInteger('profile_id');
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('media_path');
        $table->string('mime');
        $table->boolean('remote_media')->default(false);
        $table->string('cdn_url')->nullable();
        $table->string('thumbnail_path')->nullable();
        $table->string('thumbnail_url')->nullable();
        $table->string('optimized_url')->nullable();
        $table->timestamp('replicated_at')->nullable();
        $table->timestamp('processed_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('custom_filters', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('profile_id');
        $table->text('phrase');
        $table->integer('action');
        $table->json('context');
        $table->timestamp('expires_at')->nullable();
        $table->timestamps();
    });
    Schema::create('custom_filter_keywords', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('custom_filter_id');
        $table->string('keyword');
        $table->boolean('whole_word')->default(true);
        $table->timestamps();
    });
    DB::table('statuses')->delete();
    DB::table('statuses')->insert(['id' => 300, 'profile_id' => 20, 'type' => 'photo', 'scope' => 'public', 'visibility' => 'public', 'caption' => 'secret caption', 'created_at' => now()]);
    DB::table('media')->insert(['id' => 1, 'status_id' => 300, 'profile_id' => 20, 'user_id' => 2, 'media_path' => 'public/photo.jpg', 'mime' => 'image/jpeg']);
    $test->follow = Follower::withoutEvents(fn () => Follower::create(['profile_id' => 10, 'following_id' => 20, 'notify' => true]));
    $test->event = ['status_id' => '300', 'author_id' => '20', 'recipient_id' => '10', 'follower_id' => (string) $test->follow->id, 'deadline' => time() + 900];
    foreach ([10, 20, 30] as $id) {
        Cache::put(AccountService::CACHE_KEY.$id, ['id' => (string) $id, 'username' => $id === 20 ? 'bob' : 'alice']);
    }
    Cache::put('user:last_active_at:id:1', true);
    config(['filesystems.default' => 'local', 'pixelfed.cloud_storage' => false, 'pixelfed.media_fast_process' => true, 'webpush.delivery.connection' => 'redis', 'webpush.delivery.queue' => 'pushnotify']);
    Cache::put(ConfigCacheService::CACHE_KEY.'pixelfed.cloud_storage', false);
    Storage::fake('local');
    Storage::disk('local')->put('public/photo.jpg', 'usable primary fixture');
    Queue::fake();

    // Laravel RedisStore/RedisLock with an in-memory wire; never contacts Redis.
    $test->redisValues = [];
    $test->claims = [];
    $wire = Mockery::mock(Connection::class);
    $wire->shouldReceive('get')->andReturnUsing(fn ($key) => $test->redisValues[$key] ?? null);
    $wire->shouldReceive('eval')->andReturnUsing(function ($script, $keys, $key, $value, $ttl = null) use ($test) {
        if ($ttl !== null) {
            if (isset($test->redisValues[$key])) {
                return 0;
            }
            $test->redisValues[$key] = $value;

            return 1;
        }
        if (($test->claims[$key] ?? null) !== $value) {
            return 0;
        }
        unset($test->claims[$key]);

        return 1;
    });
    $wire->shouldReceive('set')->andReturnUsing(function ($key, $value, $ex, $ttl, $nx) use ($test) {
        expect($ex)->toBe('EX')->and($nx)->toBe('NX');
        if (isset($test->claims[$key])) {
            return false;
        }
        $test->claims[$key] = $value;

        return true;
    });
    $wire->shouldReceive('del')->andReturnUsing(function ($key) use ($test) {
        unset($test->redisValues[$key]);

        return 1;
    });
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('connection')->with('new_post_test')->andReturn($wire);
    Cache::extend('redis', fn () => new Repository(new RedisStore($factory, 'test:', 'new_post_test')));
    Cache::forgetDriver('redis');
}

function followedPostStatus(): ?Status
{
    return Status::find(300);
}

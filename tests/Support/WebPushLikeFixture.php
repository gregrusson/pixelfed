<?php

use App\Models\Like;
use App\Models\Notification;
use App\Models\Status;
use App\Services\AccountService;
use App\Services\RelationshipService;
use App\Services\StatusService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/WebPushCommentFixture.php';

function setupWebPushLikeFixture($test): void
{
    setupWebPushCommentFixture($test);
    Schema::table('users', fn (Blueprint $table) => $table->boolean('notify_like')->default(true));
    Schema::table('profiles', fn (Blueprint $table) => $table->string('remote_url')->nullable());
    Schema::table('statuses', function (Blueprint $table) {
        $table->boolean('local')->default(true);
        $table->string('object_url')->nullable();
        $table->string('url')->nullable();
        $table->unsignedInteger('likes_count')->default(1);
        $table->timestamps();
    });
    Schema::create('likes', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('profile_id');
        $table->unsignedBigInteger('status_id');
        $table->unsignedBigInteger('status_profile_id')->nullable();
        $table->boolean('is_comment')->nullable();
        $table->unique(['profile_id', 'status_id']);
        $table->timestamps();
        $table->softDeletes();
    });
    // The federated path does not populate status_profile_id.
    $test->like = Like::withoutEvents(fn () => Like::create([
        'profile_id' => 20, 'status_id' => 100, 'created_at' => now()->subMinute(),
    ]));
    Redis::shouldReceive('zrem', 'zadd')->andReturn(1);
    Redis::shouldReceive('zcard')->andReturn(0);
    config(['instance.notifications.nag.enabled' => false]);
}

function createLikePushNotification(array $attributes = []): Notification
{
    return Notification::create(array_merge([
        'profile_id' => 10, 'actor_id' => 20, 'item_id' => 100,
        'item_type' => Status::class, 'action' => 'like',
    ], $attributes));
}

function isolateWebPushLikeRendering(): void
{
    // Only unrelated account/status rendering caches are stubbed. Like and
    // Notification models, observers, orchestration and Redis claims stay real.
    $realCache = Cache::getFacadeRoot();
    $cache = Mockery::mock($realCache);
    $cache->shouldReceive('remember')->andReturnUsing(function ($key, $ttl, $callback) use ($realCache) {
        if (str_starts_with($key, AccountService::CACHE_KEY)) {
            return ['id' => '20', 'username' => 'bob'];
        }
        if (str_starts_with($key, RelationshipService::CACHE_KEY)) {
            return ['id' => '10'];
        }
        if ($key === StatusService::key(100, false) || $key === StatusService::key(100, true)) {
            return ['id' => '100'];
        }

        return $realCache->remember($key, $ttl, $callback);
    });
    Cache::swap($cache);
}

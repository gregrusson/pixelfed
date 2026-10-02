<?php

use App\Models\Follower;
use App\Models\Notification;
use App\Models\Profile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/WebPushCommentFixture.php';

function setupWebPushFollowFixture($test): void
{
    setupWebPushCommentFixture($test);
    Schema::table('users', fn (Blueprint $table) => $table->boolean('notify_follow')->default(true));
    Schema::table('profiles', function (Blueprint $table) {
        $table->string('private_key')->nullable();
        $table->string('remote_url')->nullable();
        $table->string('status')->nullable();
        $table->boolean('is_private')->default(false);
        $table->unsignedInteger('followers_count')->default(0);
        $table->unsignedInteger('following_count')->default(0);
        $table->timestamps();
    });
    Schema::create('followers', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedBigInteger('profile_id');
        $table->unsignedBigInteger('following_id');
        $table->unique(['profile_id', 'following_id']);
        $table->timestamps();
    });
    Schema::create('follow_requests', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('follower_id');
        $table->unsignedBigInteger('following_id');
        $table->boolean('is_rejected')->default(false);
        $table->timestamps();
    });
    DB::table('profiles')->where('id', 10)->update(['private_key' => 'local-key']);
    // Isolate unrelated follower observer/feed effects; integration tests execute
    // FollowPipeline, Notification creation and its observer without mocking them.
    $test->follower = Follower::withoutEvents(fn () => Follower::create([
        'profile_id' => 20, 'following_id' => 10,
        'created_at' => now()->subMinute(),
    ]));
    Redis::shouldReceive('zrem')->andReturn(1);
    config(['instance.notifications.nag.enabled' => false]);
}

function createFollowPushNotification(array $attributes = []): Notification
{
    return Notification::create(array_merge([
        'profile_id' => 10, 'actor_id' => 20, 'item_id' => 10,
        'item_type' => Profile::class, 'action' => 'follow',
    ], $attributes));
}

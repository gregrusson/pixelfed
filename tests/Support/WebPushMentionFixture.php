<?php

use App\Models\Mention;
use App\Models\Notification;
use App\Services\FollowerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/WebPushCommentFixture.php';

function setupWebPushMentionFixture($test): void
{
    // Reuse the isolated SQLite database and recording Redis wire, without
    // changing the established comment fixture or touching production services.
    setupWebPushCommentFixture($test);
    Schema::table('users', fn (Blueprint $table) => $table->boolean('notify_mention')->default(true));
    Schema::table('statuses', function (Blueprint $table) {
        $table->boolean('local')->default(true);
        $table->text('rendered')->nullable();
    });
    Schema::create('mentions', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('status_id');
        $table->unsignedBigInteger('profile_id');
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('followers', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('profile_id');
        $table->unsignedBigInteger('following_id');
    });
    // Exercise the actual visibility policy against follower rows. Empty
    // recorded Redis counts select FollowerService's database fallback.
    Redis::shouldReceive('zCard')->andReturn(0);
    Redis::shouldReceive('zrem')->andReturn(1);
    foreach ([10, 20, 30] as $id) {
        Cache::put(FollowerService::FOLLOWERS_SYNC_KEY.$id, true);
        Cache::put(FollowerService::FOLLOWING_SYNC_KEY.$id, true);
    }
    DB::table('statuses')->insert([
        'id' => 300, 'profile_id' => 20, 'caption' => 'private caption secret',
        'rendered' => '<p>rendered secret</p>', 'scope' => 'public', 'local' => true,
    ]);
    Mention::create(['status_id' => 300, 'profile_id' => 10]);
    config(['instance.notifications.nag.enabled' => false]);
}

function createMentionPushNotification(array $attributes = []): Notification
{
    return createCommentPushNotification(array_merge(['action' => 'mention', 'item_id' => 300], $attributes));
}

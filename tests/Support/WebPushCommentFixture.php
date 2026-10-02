<?php

use App\Models\Notification;
use App\Models\Status;
use App\Models\User;
use App\Services\WebPush\BoundedDnsResolver;
use App\Services\WebPush\DeliveryService;
use App\Services\WebPushNotificationService;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

function setupWebPushCommentFixture($test): void
{
    config(['database.default' => 'comment_push_test', 'database.connections.comment_push_test' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ], 'webpush.database_connection' => 'comment_push_test', 'webpush.delivery.ttl' => 180]);
    DB::purge('comment_push_test');
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('profile_id');
        $table->boolean('notify_comment')->default(true);
        $table->boolean('notify_enabled')->default(false);
        $table->softDeletes();
    });
    Schema::create('profiles', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('username');
        $table->string('domain')->nullable();
        $table->softDeletes();
    });
    Schema::create('statuses', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('profile_id');
        $table->unsignedBigInteger('in_reply_to_id')->nullable();
        $table->unsignedBigInteger('in_reply_to_profile_id')->nullable();
        $table->unsignedBigInteger('group_id')->nullable();
        $table->boolean('comments_disabled')->default(false);
        $table->string('scope')->default('public');
        $table->string('uri')->nullable();
        $table->text('caption')->nullable();
        $table->softDeletes();
    });
    Schema::create('notifications', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('profile_id');
        $table->unsignedBigInteger('actor_id');
        $table->unsignedBigInteger('item_id');
        $table->string('item_type');
        $table->string('action');
        $table->timestamps();
        $table->softDeletes();
    });
    Schema::create('user_filters', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('filterable_id');
        $table->string('filterable_type');
        $table->string('filter_type');
    });
    Schema::create('push_subscriptions', function (Blueprint $table) {
        $table->id();
        $table->morphs('subscribable');
        $table->string('endpoint');
        $table->string('public_key')->nullable();
        $table->string('auth_token')->nullable();
        $table->timestamps();
    });
    DB::table('users')->insert([['id' => 1, 'profile_id' => 10], ['id' => 2, 'profile_id' => 20], ['id' => 3, 'profile_id' => 30]]);
    DB::table('profiles')->insert([
        ['id' => 10, 'user_id' => 1, 'username' => 'alice'],
        ['id' => 20, 'user_id' => 2, 'username' => 'bob'],
        ['id' => 30, 'user_id' => 3, 'username' => 'charlie'],
    ]);
    DB::table('statuses')->insert([
        ['id' => 100, 'profile_id' => 10, 'in_reply_to_id' => null, 'in_reply_to_profile_id' => null, 'caption' => 'parent text'],
        ['id' => 200, 'profile_id' => 20, 'in_reply_to_id' => 100, 'in_reply_to_profile_id' => 10, 'caption' => 'private comment text'],
    ]);
    $test->subscription = User::find(1)->pushSubscriptions()->create([
        'endpoint' => 'https://push.example.com/secret-endpoint', 'public_key' => 'secret-public-key', 'auth_token' => 'secret-auth',
    ]);
    $test->delivery = Mockery::mock(DeliveryService::class);
    $test->delivery->shouldReceive('validateConfiguration')->byDefault();
    $test->delivery->shouldNotReceive('send');
    app()->instance(DeliveryService::class, $test->delivery);
    $resolver = Mockery::mock(BoundedDnsResolver::class);
    $resolver->shouldNotReceive('resolve');
    app()->instance(BoundedDnsResolver::class, $resolver);
    Http::preventStrayRequests();

    // Exercise Laravel's real RedisStore/RedisLock and verify SET EX NX, while
    // substituting only the Redis wire connection. No production Redis is used.
    $test->claims = [];
    $test->redisWire = Mockery::mock(Connection::class);
    $test->redisWire->shouldReceive('set')->byDefault()->andReturnUsing(function ($key, $owner, $ex, $seconds, $nx) use ($test) {
        expect($ex)->toBe('EX')->and($nx)->toBe('NX')
            ->and($seconds)->toBe(WebPushNotificationService::DEDUPE_SECONDS);
        if (isset($test->claims[$key])) {
            return false;
        }
        $test->claims[$key] = $owner;

        return true;
    });
    $test->redisWire->shouldReceive('eval')->andReturnUsing(function ($script, $keys, $key, $owner) use ($test) {
        if (($test->claims[$key] ?? null) !== $owner) {
            return 0;
        }
        unset($test->claims[$key]);

        return 1;
    });
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('connection')->with('shared_test')->andReturn($test->redisWire);
    $test->redisStore = new RedisStore($factory, 'test:', 'shared_test');
    Cache::extend('redis', fn () => new Repository($test->redisStore));
    Cache::forgetDriver('redis');
    // Preserve and observe the existing internal notification cache call.
    Redis::shouldReceive('exists')->andReturn(true);
    Redis::shouldReceive('pipeline')->andReturnUsing(function ($callback) {
        $pipe = Mockery::mock();
        $pipe->shouldReceive('zadd', 'zremrangebyrank', 'expire');
        $callback($pipe);
    });
}

function createCommentPushNotification(array $attributes = []): Notification
{
    return Notification::create(array_merge([
        'profile_id' => 10, 'actor_id' => 20, 'item_id' => 200,
        'item_type' => Status::class, 'action' => 'comment',
    ], $attributes));
}

function tearDownWebPushCommentFixture(): void
{
    while (DB::connection()->transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::purge('comment_push_test');
}

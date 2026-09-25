<?php

use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Models\User;
use App\Services\WebPush\BoundedDnsResolver;
use App\Services\WebPush\DeliveryException;
use App\Services\WebPush\DeliveryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Minishlink\WebPush\VAPID;

beforeEach(function () {
    // Isolated minimal database: these tests need neither federation migrations nor Redis.
    config(['database.default' => 'webpush_test', 'database.connections.webpush_test' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ], 'webpush.database_connection' => 'webpush_test']);
    DB::purge('webpush_test');
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->softDeletes();
    });
    Schema::create('push_subscriptions', function (Blueprint $table) {
        $table->id();
        $table->morphs('subscribable');
        $table->string('endpoint');
        $table->string('public_key')->nullable();
        $table->string('auth_token')->nullable();
        $table->string('content_encoding')->nullable();
        $table->timestamps();
    });
    DB::table('users')->insert([['id' => 1], ['id' => 2]]);
    $this->user = User::find(1);
    $this->sub = $this->user->pushSubscriptions()->create([
        'endpoint' => 'https://push.example.com/secret', 'public_key' => 'secret-public-key', 'auth_token' => 'secret-auth',
    ]);
    $this->sub->forceFill(['updated_at' => now()->subSeconds(5)])->save();
    $this->service = Mockery::mock(DeliveryService::class);
});

afterEach(function () {
    DB::purge('webpush_test');
});

function queuedWebPush(string $user, string $subscription, int $attempt = 1, ?int $expiry = null): DeliverWebPush
{
    $job = new DeliverWebPush($user, $subscription, ['title' => 'Pixelfed'], $expiry ?? time() + 300);
    $worker = Mockery::mock(RedisJob::class);
    $worker->shouldReceive('attempts')->andReturn($attempt);
    $job->setJob($worker);

    return $job;
}

it('does not load another users subscription, missing user or deleted subscription', function (string $user, string $subscription) {
    $this->service->shouldNotReceive('send');
    queuedWebPush($user, $subscription)->handle($this->service);
    expect($this->user->pushSubscriptions()->count())->toBe(1);
})->with([['2', '1'], ['999', '1'], ['1', '999']]);

it('requires an actual Redis worker context', function () {
    $this->service->shouldNotReceive('send');
    expect(fn () => (new DeliverWebPush('1', '1', [], time() + 300))->handle($this->service))
        ->toThrow(DeliveryException::class, 'queue_worker_required');
});

it('does not send an expired job', function () {
    $this->service->shouldNotReceive('send');
    queuedWebPush('1', '1', expiry: time() - 1)->handle($this->service);
    expect(true)->toBeTrue();
});

it('completes a successful delivery independently', function () {
    $this->service->shouldReceive('send')->once()->withArgs(fn ($sub, $payload, $ttl) => $sub->id === $this->sub->id && $ttl > 0 && $ttl <= 300)->andReturn('success');
    queuedWebPush('1', '1')->handle($this->service);
    expect($this->user->pushSubscriptions()->count())->toBe(1);
});

it('deletes the expired subscription only', function () {
    $other = User::find(2)->pushSubscriptions()->create(['endpoint' => 'https://push.example.com/other']);
    $this->service->shouldReceive('send')->once()->andReturn('expired');
    queuedWebPush('1', '1')->handle($this->service);
    expect($this->user->pushSubscriptions()->count())->toBe(0)
        ->and($other->fresh())->not->toBeNull();
});

it('does not delete a subscription refreshed during delivery', function () {
    $this->service->shouldReceive('send')->once()->andReturnUsing(function ($sub) {
        $sub->update(['auth_token' => 'refreshed-secret']);

        return 'expired';
    });
    queuedWebPush('1', '1')->handle($this->service);
    expect($this->sub->fresh()->auth_token)->toBe('refreshed-secret');
});

it('rethrows only sanitized transient failures for Laravel backoff', function () {
    $this->service->shouldReceive('send')->andThrow(new DeliveryException('provider_temporary', true));
    $job = queuedWebPush('1', '1');
    expect(fn () => $job->handle($this->service))->toThrow(DeliveryException::class, 'provider_temporary')
        ->and($job->tries)->toBe(3)->and($job->backoff)->toBe([30, 120])
        ->and($job->connection)->toBe('redis')->and($job->queue)->toBe('pushnotify');
});

it('fails permanently on permanent failures, exhaustion or insufficient lifetime', function (bool $retry, int $attempt, int $remaining) {
    $this->service->shouldReceive('send')->andThrow(new DeliveryException('safe_category', $retry));
    $job = queuedWebPush('1', '1', $attempt, time() + $remaining);
    $job->job->shouldReceive('fail')->once()->withArgs(fn ($e) => (string) $e === 'Web Push: safe_category' && $e->getPrevious() === null);
    $job->handle($this->service);
})->with([[false, 1, 300], [true, 3, 300], [true, 1, 10]]);

it('sanitizes unexpected failures before failed job storage', function () {
    $this->service->shouldReceive('send')->andThrow(new RuntimeException('secret-auth secret-public-key secret-endpoint'));
    $job = queuedWebPush('1', '1');
    $job->job->shouldReceive('fail')->once()->withArgs(fn ($e) => (string) $e === 'Web Push: delivery_failed');
    $job->handle($this->service);
});

it('queues only the requested users identifiers without secrets or network calls', function () {
    $keys = VAPID::createVapidKeys();
    config(['webpush.vapid' => ['subject' => 'mailto:admin@example.com', 'public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey']]]);
    User::find(2)->pushSubscriptions()->create(['endpoint' => 'https://push.example.com/other']);
    $resolver = Mockery::mock(BoundedDnsResolver::class);
    $resolver->shouldNotReceive('resolve');
    $this->app->instance(BoundedDnsResolver::class, $resolver);
    Queue::fake();
    $this->artisan('webpush:test', ['user-id' => '1'])->expectsOutput('Queued 1 Web Push test deliveries on redis:pushnotify.')->assertSuccessful();
    Queue::assertPushed(DeliverWebPush::class, function ($job) {
        expect(serialize($job))->not->toContain('secret-auth', 'secret-public-key', 'secret');

        return $job->userId === '1' && $job->subscriptionId === '1' && $job->payload['body'] === 'Web Push is working';
    });
    Queue::assertPushed(DeliverWebPush::class, 1);
});

it('rejects a synchronous delivery queue configuration', function () {
    $this->service->shouldReceive('validateConfiguration')->once();
    $this->app->instance(DeliveryService::class, $this->service);
    config(['webpush.delivery.connection' => 'sync']);
    Queue::fake();
    $this->artisan('webpush:test', ['user-id' => '1'])->assertFailed();
    Queue::assertNothingPushed();
});

it('defers expiry cleanup when a same-second refresh cannot be distinguished', function () {
    $timestamp = now()->addSeconds(2); // Also cover clock skew; never delete a future-dated row.
    $this->sub->forceFill(['updated_at' => $timestamp])->save();
    $this->service->shouldReceive('send')->once()->andReturnUsing(function () use ($timestamp) {
        DB::table('push_subscriptions')->where('id', 1)->update(['updated_at' => $timestamp]);

        return 'expired';
    });
    queuedWebPush('1', '1')->handle($this->service);
    expect($this->sub->fresh())->not->toBeNull();
});

it('does not delete a refreshed row when the in-memory subscription remains stale', function () {
    $this->service->shouldReceive('send')->once()->andReturnUsing(function () {
        DB::table('push_subscriptions')->where('id', 1)->update(['public_key' => 'replacement-key']);

        return 'expired';
    });
    queuedWebPush('1', '1')->handle($this->service);
    expect($this->sub->fresh()->public_key)->toBe('replacement-key');
});

it('refuses actual synchronous bus execution', function () {
    $this->service->shouldNotReceive('send');
    $this->app->instance(DeliveryService::class, $this->service);
    expect(fn () => Bus::dispatchSync(new DeliverWebPush('1', '1', [], time() + 300)))
        ->toThrow(DeliveryException::class, 'queue_worker_required');
});

it('rejects all non-Redis queue drivers before dispatching', function (string $driver) {
    $this->service->shouldReceive('validateConfiguration')->once();
    $this->app->instance(DeliveryService::class, $this->service);
    config(['queue.connections.redis.driver' => $driver]);
    Queue::fake();
    $this->artisan('webpush:test', ['user-id' => '1'])->assertFailed();
    Queue::assertNothingPushed();
})->with(['sync', 'deferred', 'background', 'failover', 'null', 'database']);

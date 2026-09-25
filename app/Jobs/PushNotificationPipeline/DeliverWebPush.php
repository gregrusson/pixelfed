<?php

namespace App\Jobs\PushNotificationPipeline;

use App\Models\User;
use App\Services\WebPush\DeliveryException;
use App\Services\WebPush\DeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\RedisJob;
use Throwable;

class DeliverWebPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $timeout = 20;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $userId,
        public readonly string $subscriptionId,
        public readonly array $payload,
        public readonly int $expiresAt,
    ) {
        $this->onConnection(config('webpush.delivery.connection', 'redis'));
        $this->onQueue(config('webpush.delivery.queue', 'pushnotify'));
    }

    public function handle(DeliveryService $delivery): void
    {
        // ShouldQueue alone does not prevent dispatchSync or a sync connection.
        if (PHP_SAPI !== 'cli' || ! $this->job instanceof RedisJob) {
            throw new DeliveryException('queue_worker_required');
        }
        if ($this->expiresAt <= time()) {
            return;
        }
        try {
            $startedAt = time();
            $user = User::find($this->userId);
            $subscription = $user?->pushSubscriptions()->whereKey($this->subscriptionId)->first();
            if (! $subscription) {
                return;
            }
            $snapshot = $subscription->getAttributes();
            $outcome = $delivery->send($subscription, $this->payload, min(300, $this->expiresAt - time()));
            // Timestamps have second precision. Within this second an identical re-registration
            // is indistinguishable from our snapshot, so conservatively defer expiry cleanup.
            if ($outcome === 'expired' && $subscription->updated_at && $subscription->updated_at->getTimestamp() < $startedAt) {
                $subscription->getConnection()->transaction(function () use ($user, $snapshot) {
                    $current = $user->pushSubscriptions()->whereKey($this->subscriptionId)->lockForUpdate()->first();
                    // Compare under the row lock; a concurrent key/endpoint refresh must survive.
                    if ($current && $current->getAttributes() === $snapshot) {
                        $current->delete();
                    }
                });
            }
        } catch (DeliveryException $e) {
            $delay = $this->backoff[min(max($this->attempts() - 1, 0), 1)];
            if ($e->retryable && $this->attempts() < $this->tries && time() + $delay < $this->expiresAt) {
                throw $e;
            }
            $this->fail(new DeliveryException($e->category));
        } catch (Throwable) {
            // SQL and transport exception context must never enter failed_jobs/Horizon.
            $this->fail(new DeliveryException('delivery_failed'));
        }
    }
}

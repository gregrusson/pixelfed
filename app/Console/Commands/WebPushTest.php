<?php

namespace App\Console\Commands;

use App\Jobs\PushNotificationPipeline\DeliverWebPush;
use App\Models\User;
use App\Services\WebPush\DeliveryException;
use App\Services\WebPush\DeliveryQueueConfiguration;
use App\Services\WebPush\DeliveryService;
use Illuminate\Console\Command;
use Throwable;

class WebPushTest extends Command
{
    protected $signature = 'webpush:test {user-id}';

    protected $description = 'Queue a generic Web Push test for a user (server CLI only)';

    public function handle(DeliveryService $delivery): int
    {
        try {
            if (PHP_SAPI !== 'cli') {
                throw new DeliveryException('cli_required');
            }
            $delivery->validateConfiguration();
            [$connection, $queue, $ttl] = DeliveryQueueConfiguration::validated();
            $user = User::find($this->argument('user-id'));
            if (! $user) {
                $this->error('User not found.');

                return self::FAILURE;
            }
            $count = 0;
            $expires = time() + $ttl;
            foreach ($user->pushSubscriptions()->select('id')->lazyById() as $subscription) {
                DeliverWebPush::dispatch((string) $user->getKey(), (string) $subscription->id, [
                    'notification_type' => 'test',
                    'title' => 'Pixelfed',
                    'body' => 'Web Push is working',
                    'url' => '/',
                ], $expires);
                $count++;
            }
            $this->info("Queued {$count} Web Push test deliveries on {$connection}:{$queue}.");

            return self::SUCCESS;
        } catch (DeliveryException $e) {
            $this->error($e->getMessage());
        } catch (Throwable) {
            $this->error('Web Push: command_failed');
        }

        return self::FAILURE;
    }
}

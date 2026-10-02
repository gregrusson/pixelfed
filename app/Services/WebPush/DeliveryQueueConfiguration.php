<?php

namespace App\Services\WebPush;

class DeliveryQueueConfiguration
{
    /** @return array{string, string, int} */
    public static function validated(): array
    {
        $connection = config('webpush.delivery.connection', 'redis');
        $queue = config('webpush.delivery.queue', 'pushnotify');
        if (! is_string($connection) || config("queue.connections.{$connection}.driver") !== 'redis'
            || ! is_string($queue) || ! preg_match('/\A[a-zA-Z0-9_-]+\z/D', $queue)) {
            throw new DeliveryException('persistent_redis_queue_required');
        }
        $ttl = config('webpush.delivery.ttl', 300);
        if (! is_int($ttl) || $ttl < 1 || $ttl > 300) {
            throw new DeliveryException('invalid_ttl');
        }

        return [$connection, $queue, $ttl];
    }
}

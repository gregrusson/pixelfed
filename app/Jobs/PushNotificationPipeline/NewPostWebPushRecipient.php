<?php

namespace App\Jobs\PushNotificationPipeline;

use App\Services\WebPushFollowedPostService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class NewPostWebPushRecipient implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly array $event, public readonly string $userId)
    {
        $this->onConnection('redis')->onQueue('feed')->afterCommit();
    }

    public function handle(): void
    {
        WebPushFollowedPostService::notify($this->event, $this->userId);
    }
}

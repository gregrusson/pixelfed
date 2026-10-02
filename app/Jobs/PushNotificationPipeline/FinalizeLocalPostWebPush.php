<?php

namespace App\Jobs\PushNotificationPipeline;

use App\Models\Status;
use App\Services\WebPush\LocalPublicationSupport;
use App\Services\WebPushFollowedPostService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class FinalizeLocalPostWebPush implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $statusId, public readonly string $authorId, public readonly string $token)
    {
        $this->onConnection('redis')->onQueue('feed')->afterCommit();
    }

    public function handle(): void
    {
        $status = Status::find($this->statusId);
        if ($status && $status->getConnection()->transactionLevel() > 0) {
            WebPushFollowedPostService::afterCommit($status, fn () => $this->handle());

            return;
        }
        try {
            $record = LocalPublicationSupport::ready($this->statusId, $this->authorId, $this->token);
            if (! $record) {
                return;
            }
            $seconds = $record['deadline'] - time();
            if ($seconds <= 0) {
                return;
            }
            // Claim only after readiness. Generic completion/callback retries may
            // revisit readiness; they cannot create another accepted generation.
            $claim = Cache::store('redis')->lock('webpush:local_publication:accepted:'.$this->token, $seconds);
            if (! $claim->get()) {
                return;
            }
            NewPostWebPushFanout::dispatch($this->statusId, $this->authorId, $record['deadline'])->afterCommit();
            // Uncertain enqueue acceptance retains the claim (possible loss).
        } catch (Throwable) {
            WebPushFollowedPostService::report('finalization_failed');
        }
    }
}

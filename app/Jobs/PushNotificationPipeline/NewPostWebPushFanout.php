<?php

namespace App\Jobs\PushNotificationPipeline;

use App\Models\Follower;
use App\Models\Status;
use App\Services\WebPushFollowedPostService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class NewPostWebPushFanout implements ShouldQueue
{
    use Queueable;

    public const PAGE_SIZE = 200;

    public int $tries = 3;

    public function __construct(
        public readonly string $statusId,
        public readonly string $authorId,
        public readonly int $deadline,
        public readonly int $afterId = 0,
    ) {
        $this->onConnection('redis')->onQueue('feed')->afterCommit();
    }

    public function handle(): void
    {
        if ($this->deadline <= time() || ! WebPushFollowedPostService::eligibleStatus(Status::find($this->statusId), $this->authorId)) {
            return;
        }
        $rows = Follower::query()->join('profiles', 'profiles.id', '=', 'followers.profile_id')
            ->where('followers.following_id', $this->authorId)->where('followers.notify', true)
            ->where('followers.id', '>', $this->afterId)
            ->whereNull('profiles.domain')->whereNull('profiles.deleted_at')->whereNotNull('profiles.user_id')
            ->orderBy('followers.id')->limit(self::PAGE_SIZE)
            ->get(['followers.id', 'followers.profile_id', 'profiles.user_id']);
        foreach ($rows as $row) {
            NewPostWebPushRecipient::dispatch([
                'status_id' => $this->statusId, 'author_id' => $this->authorId,
                'recipient_id' => (string) $row->profile_id, 'follower_id' => (string) $row->id,
                'deadline' => $this->deadline,
            ], (string) $row->user_id)->afterCommit();
        }
        if ($rows->count() === self::PAGE_SIZE) {
            self::dispatch($this->statusId, $this->authorId, $this->deadline, (int) $rows->last()->id)->afterCommit();
        }
    }
}

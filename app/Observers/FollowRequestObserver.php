<?php

namespace App\Observers;

use App\Models\FollowRequest;
use App\Services\WebPushFollowRequestService;

class FollowRequestObserver
{
    public bool $afterCommit = true;

    public function created(FollowRequest $request): void
    {
        WebPushFollowRequestService::notify($request);
    }
}

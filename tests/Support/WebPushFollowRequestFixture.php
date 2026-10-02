<?php

use App\Models\Follower;
use App\Models\FollowRequest;
use App\Models\Profile;
use App\Services\InstanceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/WebPushFollowFixture.php';

function setupWebPushFollowRequestFixture($test): void
{
    setupWebPushFollowFixture($test);
    DB::table('followers')->delete();
    DB::table('profiles')->where('id', 10)->update(['is_private' => true]);
    Schema::table('follow_requests', function (Blueprint $table) {
        $table->json('activity')->nullable();
        $table->boolean('is_local')->default(false);
        $table->timestamp('handled_at')->nullable();
    });
    Schema::table('profiles', function (Blueprint $table) {
        $table->unsignedBigInteger('moved_to_profile_id')->nullable();
        $table->string('inbox_url')->nullable();
        $table->string('sharedInbox')->nullable();
    });
    Schema::create('user_domain_blocks', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('profile_id');
        $table->string('domain');
    });
    Schema::create('instances', function (Blueprint $table) {
        $table->id();
        $table->string('domain');
        $table->boolean('banned')->default(false);
    });
    Cache::forget(InstanceService::CACHE_KEY_BANNED_DOMAINS);
}

function createPendingWebPushRequest(array $attributes = []): FollowRequest
{
    return FollowRequest::create(array_merge(['follower_id' => 20, 'following_id' => 10], $attributes));
}

function makeWebPushRequestActorRemote(): void
{
    DB::table('profiles')->where('id', 20)->update([
        'domain' => 'remote.example', 'username' => '@bob@remote.example', 'user_id' => null,
        'remote_url' => 'https://remote.example/users/bob', 'inbox_url' => 'https://remote.example/inbox',
    ]);
    DB::table('profiles')->where('id', 10)->update(['remote_url' => 'https://pixelfed.example/users/alice']);
}

function changeWebPushRequestEligibility(string $change, FollowRequest $request): void
{
    match ($change) {
        'missing_request' => DB::table('follow_requests')->where('id', $request->id)->delete(),
        'actor_identity' => DB::table('follow_requests')->where('id', $request->id)->update(['follower_id' => 30]),
        'recipient_identity' => DB::table('follow_requests')->where('id', $request->id)->update(['following_id' => 30]),
        'missing_actor' => DB::table('profiles')->where('id', 20)->delete(),
        'deleted_actor' => DB::table('profiles')->where('id', 20)->update(['deleted_at' => now()]),
        'missing_recipient' => DB::table('profiles')->where('id', 10)->delete(),
        'deleted_recipient' => DB::table('profiles')->where('id', 10)->update(['deleted_at' => now()]),
        'remote_recipient' => DB::table('profiles')->where('id', 10)->update(['domain' => 'remote.example']),
        'public_recipient' => DB::table('profiles')->where('id', 10)->update(['is_private' => false]),
        'no_user_id' => DB::table('profiles')->where('id', 10)->update(['user_id' => null]),
        'missing_user' => DB::table('users')->where('id', 1)->delete(),
        'deleted_user' => DB::table('users')->where('id', 1)->update(['deleted_at' => now()]),
        'user_mismatch' => DB::table('users')->where('id', 1)->update(['profile_id' => 30]),
        'rejected' => DB::table('follow_requests')->where('id', $request->id)->update(['is_rejected' => true]),
        'handled' => DB::table('follow_requests')->where('id', $request->id)->update(['handled_at' => now()]),
        'following' => Follower::withoutEvents(fn () => Follower::create(['profile_id' => 20, 'following_id' => 10])),
        'preference' => DB::table('users')->where('id', 1)->update(['notify_follow' => false]),
        'subscription' => DB::table('push_subscriptions')->delete(),
        'mute', 'block' => DB::table('user_filters')->insert([
            'user_id' => 10, 'filterable_type' => Profile::class, 'filterable_id' => 20, 'filter_type' => $change,
        ]),
        'username' => DB::table('profiles')->where('id', 20)->update(['username' => 'bob<script>']),
    };
}

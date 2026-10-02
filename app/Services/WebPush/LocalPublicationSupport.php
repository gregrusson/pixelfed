<?php

namespace App\Services\WebPush;

use App\Jobs\PushNotificationPipeline\FinalizeLocalPostWebPush;
use App\Models\Media;
use App\Models\Status;
use App\Services\WebPushFollowedPostService;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

class LocalPublicationSupport
{
    private static function key(string $sid, string $aid): string
    {
        return 'webpush:local_publication:'.$sid.':'.$aid;
    }

    private static function cache()
    {
        $cache = Cache::store('redis');
        if (! $cache->getStore() instanceof RedisStore) {
            throw new \RuntimeException('redis_required');
        }

        return $cache;
    }

    /** Called only by trusted fresh-media publish endpoints, never callbacks. */
    public static function authorize(Status $status): void
    {
        try {
            if (! WebPushFollowedPostService::eligibleStatus($status, (string) $status->profile_id)) {
                return;
            }
            self::authorizeIdentity($status);
        } catch (Throwable) {
            WebPushFollowedPostService::report('authorization_failed');
        }
    }

    private static function authorizeIdentity(Status $status): void
    {
        $sid = (string) $status->id;
        $aid = (string) $status->profile_id;
        $connection = $status->getConnectionName();
        $accepted = time();
        $token = bin2hex(random_bytes(32));
        // Snapshot the actual attached identities before deferral; replacements
        // after commit cannot become the attachment generation being authorized.
        $mediaIds = $status->media()->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
        WebPushFollowedPostService::afterCommit($status, function () use ($sid, $aid, $connection, $accepted, $token, $mediaIds) {
            try {
                $status = Status::on($connection)->find($sid);
                if (! WebPushFollowedPostService::eligibleStatus($status, $aid) || ! $status->local
                    || $status->uri !== null || $mediaIds === []) {
                    return;
                }
                $record = [
                    'origin' => 'local_media_publish_v1', 'token' => $token,
                    'status_id' => $sid, 'author_id' => $aid, 'media_ids' => $mediaIds,
                    'accepted_at' => $accepted, 'deadline' => $accepted + WebPushFollowedPostService::EVENT_SECONDS,
                ];
                $ttl = $record['deadline'] - time();
                if ($ttl <= 0) {
                    return;
                }
                // Bounded operational state, NOT a transactional outbox. add()
                // never renews an existing publication's acceptance/deadline.
                self::cache()->add(self::key($sid, $aid).':authorization', $record, $ttl);
                self::resume($sid, $aid);
            } catch (Throwable) {
                WebPushFollowedPostService::report('authorization_failed');
            }
        });
    }

    public static function completed(Status $status): void
    {
        $sid = (string) $status->id;
        $aid = (string) $status->profile_id;
        $connection = $status->getConnectionName();
        WebPushFollowedPostService::afterCommit($status, function () use ($sid, $aid, $connection) {
            try {
                $status = Status::on($connection)->find($sid);
                if (! WebPushFollowedPostService::eligibleStatus($status, $aid) || ! $status->local || $status->uri !== null) {
                    return;
                }
                // This bounded signal has NO authorizing power. It permits the
                // callback winning the lexer race before endpoint registration.
                self::cache()->add(self::key($sid, $aid).':completion', time(), WebPushFollowedPostService::EVENT_SECONDS);
                self::resume($sid, $aid);
            } catch (Throwable) {
                WebPushFollowedPostService::report('completion_failed');
            }
        });
    }

    /** Generic callbacks can only resume an existing authorization. */
    public static function resume(string $sid, string $aid): void
    {
        try {
            $record = self::authorization($sid, $aid);
            if ($record && self::cache()->get(self::key($sid, $aid).':completion') !== null) {
                FinalizeLocalPostWebPush::dispatch($sid, $aid, $record['token'])->afterCommit();
            }
        } catch (Throwable) {
            WebPushFollowedPostService::report('resume_failed');
        }
    }

    public static function authorization(string $sid, string $aid): ?array
    {
        $record = self::cache()->get(self::key($sid, $aid).':authorization');
        if (! is_array($record) || ($record['origin'] ?? null) !== 'local_media_publish_v1'
            || ($record['status_id'] ?? null) !== $sid || ($record['author_id'] ?? null) !== $aid
            || ($record['deadline'] ?? 0) <= time()) {
            return null;
        }

        return $record;
    }

    public static function ready(string $sid, string $aid, string $token): ?array
    {
        $record = self::authorization($sid, $aid);
        if (! $record || ! hash_equals($record['token'], $token)
            || self::cache()->get(self::key($sid, $aid).':completion') === null
            || config('filesystems.default') !== 'local') {
            // Direct-default S3 is outside the existing staging/readiness model.
            return null;
        }
        $status = Status::find($sid);
        if (! WebPushFollowedPostService::eligibleStatus($status, $aid) || ! $status->local || $status->uri !== null) {
            return null;
        }
        $ids = $record['media_ids'];
        $media = Media::whereStatusId($sid)->orderBy('id')->get();
        if ($ids === [] || $media->pluck('id')->map(fn ($id) => (string) $id)->all() !== $ids) {
            return null;
        }
        $photos = 0;
        $videos = 0;
        foreach ($media as $part) {
            if ($part->remote_media || (string) $part->profile_id !== $aid || ! is_string($part->media_path) || $part->media_path === '') {
                return null;
            }
            if (in_array($part->mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/heic', 'image/avif'], true)) {
                $photos++;
            } elseif ($part->mime === 'video/mp4') {
                $videos++;
            } else {
                return null;
            }
            $slowCloud = config_cache('pixelfed.cloud_storage') && ! config('pixelfed.media_fast_process');
            if ($slowCloud) {
                if (! is_string($part->cdn_url) || $part->cdn_url === '') {
                    return null;
                }
            } elseif (! (config_cache('pixelfed.cloud_storage') && $part->cdn_url)
                && ! Storage::disk('local')->exists($part->media_path)) {
                // Cloud fast mode may use the local original or already replicated
                // primary. Thumbnails/HLS/processed_at are not publication gates.
                return null;
            }
        }
        $type = match (true) {
            $photos === 1 && $videos === 0 => 'photo',
            $photos > 1 && $videos === 0 => 'photo:album',
            $videos === 1 && $photos === 0 => 'video',
            $videos > 1 && $photos === 0 => 'video:album',
            $photos > 0 && $videos > 0 => 'photo:video:album',
            default => null,
        };

        return $type === $status->type ? $record : null;
    }
}

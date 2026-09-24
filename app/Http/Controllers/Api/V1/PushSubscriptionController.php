<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\VAPID;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->tokenCan('push'), 403);

        $data = $request->validate([
            'subscription.endpoint' => ['required', 'string', 'max:500'],
            'subscription.keys.p256dh' => ['required', 'string', 'max:191'],
            'subscription.keys.auth' => ['required', 'string', 'max:191'],
        ]);

        $vapid = config('webpush.vapid');
        abort_unless($this->hasValidVapidConfiguration($vapid), 503, 'Web Push is not configured.');

        $user = $request->user();
        $endpoint = $data['subscription']['endpoint'];
        $model = config('webpush.model');
        $existing = $model::findByEndpoint($endpoint);

        abort_if($existing && ! $user->ownsPushSubscription($existing), 409, 'Push endpoint is already registered.');

        try {
            // The package's updatePushSubscription() deletes subscriptions owned by other users.
            $subscription = $user->pushSubscriptions()->updateOrCreate(
                ['endpoint' => $endpoint],
                [
                    'public_key' => $data['subscription']['keys']['p256dh'],
                    'auth_token' => $data['subscription']['keys']['auth'],
                    'content_encoding' => ContentEncoding::aes128gcm,
                ]
            );
        } catch (UniqueConstraintViolationException) {
            // A concurrent registration may have claimed the globally unique endpoint.
            abort(409, 'Push endpoint is already registered.');
        }

        return response()->json([
            'id' => (string) $subscription->id,
            'endpoint' => $subscription->endpoint,
            'server_key' => $vapid['public_key'],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        abort_unless($request->user()->tokenCan('push'), 403);

        $data = $request->validate([
            'subscription.endpoint' => ['required', 'string', 'max:500'],
        ]);

        $request->user()->deletePushSubscription($data['subscription']['endpoint']);

        return response()->json([]);
    }

    private function hasValidVapidConfiguration(mixed $vapid): bool
    {
        if (! is_array($vapid)) {
            return false;
        }

        foreach (['subject', 'public_key', 'private_key'] as $key) {
            if (! isset($vapid[$key]) || ! is_string($vapid[$key]) || trim($vapid[$key]) === '') {
                return false;
            }
        }

        $subject = $vapid['subject'];
        $validSubject = str_starts_with($subject, 'mailto:')
            ? filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL)
            : (filter_var($subject, FILTER_VALIDATE_URL) && parse_url($subject, PHP_URL_SCHEME) === 'https');
        if (! $validSubject) {
            return false;
        }

        try {
            VAPID::validate([
                'subject' => $vapid['subject'],
                'publicKey' => $vapid['public_key'],
                'privateKey' => $vapid['private_key'],
            ]);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}

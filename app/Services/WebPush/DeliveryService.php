<?php

namespace App\Services\WebPush;

use GuzzleHttp\Psr7\HttpFactory;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\PushSubscription;
use Psr\Http\Client\ClientInterface;
use Psr\Log\NullLogger;
use Throwable;

class DeliveryService
{
    public function __construct(private readonly BoundedDnsResolver $resolver) {}

    public function validateConfiguration(): void
    {
        $this->auth();
        PinnedHttpClient::assertAvailable();
    }

    private function auth(): array
    {
        try {
            $auth = [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ];
            foreach ($auth as $value) {
                if (! is_string($value) || $value === '') {
                    throw new DeliveryException('invalid_vapid_configuration');
                }
            }
            $subject = $auth['subject'];
            $validSubject = str_starts_with($subject, 'mailto:')
                ? filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL)
                : (filter_var($subject, FILTER_VALIDATE_URL) && parse_url($subject, PHP_URL_SCHEME) === 'https');
            if (! $validSubject) {
                throw new DeliveryException('invalid_vapid_configuration');
            }
            $decoded = VAPID::validate($auth);
            // Derive the P-256 public point from the private scalar, checking that the pair matches.
            $der = hex2bin('30310201010420').$decoded['privateKey'].hex2bin('a00a06082a8648ce3d030107');
            $private = @openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n");
            $ec = $private ? (openssl_pkey_get_details($private)['ec'] ?? []) : [];
            if (! isset($ec['x'], $ec['y']) || ! hash_equals($decoded['publicKey'], "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT))) {
                throw new DeliveryException('invalid_vapid_configuration');
            }

            return ['VAPID' => $auth];
        } catch (Throwable) {
            throw new DeliveryException('invalid_vapid_configuration');
        }
    }

    /** Returns only a safe outcome; all failure exceptions contain fixed categories. */
    public function send(#[\SensitiveParameter] PushSubscription $row, array $payload, int $ttl): string
    {
        if (PHP_SAPI !== 'cli') {
            throw new DeliveryException('queue_worker_required');
        }
        try {
            $endpoint = (new EndpointPolicy)->canonicalize($row->endpoint);
            $this->validateKeys($row);
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            if (strlen($json) > 2048 || $ttl <= 0 || $ttl > 300) {
                throw new DeliveryException('invalid_payload');
            }
            $subscription = new Subscription($endpoint, $row->public_key, $row->auth_token, $row->content_encoding ?? ContentEncoding::aes128gcm);
            $webPush = new WebPush($this->auth(), ['TTL' => $ttl], $this->client($endpoint), new HttpFactory, new HttpFactory, logger: new NullLogger);
            $report = $webPush->sendOneNotification($subscription, $json);
            $status = $report->getResponse()?->getStatusCode();
            if ($status !== null && $status >= 200 && $status < 300) {
                return 'success';
            }
            if (in_array($status, [404, 410], true)) {
                return 'expired';
            }
            if (in_array($status, [408, 429], true) || ($status !== null && $status >= 500 && $status <= 599)) {
                throw new DeliveryException('provider_temporary', true);
            }
            throw new DeliveryException('provider_rejected');
        } catch (DeliveryException $e) {
            // Recreate after unwinding Minishlink: its stack frames can contain subscription keys.
            throw new DeliveryException($e->category, $e->retryable);
        } catch (Throwable) {
            throw new DeliveryException('invalid_subscription_or_configuration');
        }
    }

    protected function client(#[\SensitiveParameter] string $endpoint): ClientInterface
    {
        return new PinnedHttpClient($endpoint, $this->resolver, config('webpush.delivery', []));
    }

    private function validateKeys(#[\SensitiveParameter] PushSubscription $row): void
    {
        $decode = static function ($value) {
            if (! is_string($value) || ! preg_match('/\A[A-Za-z0-9_-]+={0,2}\z/D', $value)) {
                throw new DeliveryException('invalid_subscription_keys');
            }

            return base64_decode(strtr($value, '-_', '+/'), true);
        };
        $key = $decode($row->public_key);
        $auth = $decode($row->auth_token);
        if (! is_string($key) || strlen($key) !== 65 || $key[0] !== "\x04" || ! is_string($auth) || strlen($auth) !== 16) {
            throw new DeliveryException('invalid_subscription_keys');
        }
        // RFC 5480 SubjectPublicKeyInfo for an uncompressed prime256v1 point.
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$key;
        if (@openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n") === false) {
            throw new DeliveryException('invalid_subscription_keys');
        }
    }
}

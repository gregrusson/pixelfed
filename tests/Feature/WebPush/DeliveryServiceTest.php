<?php

use App\Services\WebPush\BoundedDnsResolver;
use App\Services\WebPush\DeliveryException;
use App\Services\WebPush\DeliveryService;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\VAPID;
use NotificationChannels\WebPush\PushSubscription;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class WebPushResponseClient implements ClientInterface
{
    public ?RequestInterface $request = null;

    public function __construct(private int $status) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return new Response($this->status);
    }
}

class WebPushFixtureDelivery extends DeliveryService
{
    public int $clientCalls = 0;

    public function __construct(public ClientInterface $http)
    {
        parent::__construct(new BoundedDnsResolver);
    }

    protected function client(string $endpoint): ClientInterface
    {
        $this->clientCalls++;

        return $this->http;
    }
}

beforeEach(function () {
    $keys = VAPID::createVapidKeys();
    config(['webpush.vapid' => ['subject' => 'mailto:admin@example.com', 'public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey']]]);
    $browser = VAPID::createVapidKeys();
    $this->subscription = new PushSubscription([
        'endpoint' => 'https://push.example.com/secret-endpoint',
        'public_key' => $browser['publicKey'],
        'auth_token' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
        'content_encoding' => ContentEncoding::aes128gcm,
    ]);
});

it('injects PSR18, encrypts a single message and accepts only 2xx', function (int $status) {
    $client = new WebPushResponseClient($status);
    $service = new WebPushFixtureDelivery($client);
    expect($service->send($this->subscription, ['title' => 'Pixelfed'], 300))->toBe('success')
        ->and($service->clientCalls)->toBe(1)
        ->and($client->request->getMethod())->toBe('POST')
        ->and($client->request->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
        ->and($client->request->getHeaderLine('TTL'))->toBe('300')
        ->and((string) $client->request->getBody())->not->toContain('Pixelfed');
})->with([200, 201, 202, 204, 299]);

it('uses aes128gcm for a legacy null encoding', function () {
    $this->subscription->content_encoding = null;
    $client = new WebPushResponseClient(201);
    (new WebPushFixtureDelivery($client))->send($this->subscription, ['title' => 'Pixelfed'], 100);
    expect($client->request->getHeaderLine('Content-Encoding'))->toBe('aes128gcm');
});

it('recognizes expired subscriptions', function (int $status) {
    expect((new WebPushFixtureDelivery(new WebPushResponseClient($status)))->send($this->subscription, [], 300))->toBe('expired');
})->with([404, 410]);

it('classifies provider failures without raw reports', function (int $status, bool $retry) {
    try {
        (new WebPushFixtureDelivery(new WebPushResponseClient($status)))->send($this->subscription, [], 300);
        $this->fail('Expected classified failure');
    } catch (DeliveryException $e) {
        expect($e->retryable)->toBe($retry)->and($e->getPrevious())->toBeNull()
            ->and((string) $e)->not->toContain('secret-endpoint', $this->subscription->auth_token, config('webpush.vapid.private_key'));
    }
})->with([[301, false], [302, false], [307, false], [400, false], [401, false], [403, false], [408, true], [429, true], [500, true], [503, true]]);

it('rejects invalid endpoints and keys before constructing the transport', function () {
    $service = new WebPushFixtureDelivery(new WebPushResponseClient(201));
    $this->subscription->endpoint = 'http://localhost/secret';
    expect(fn () => $service->send($this->subscription, [], 300))->toThrow(DeliveryException::class);
    $this->subscription->endpoint = 'https://push.example.com/';
    $this->subscription->auth_token = 'bad-auth-secret';
    expect(fn () => $service->send($this->subscription, [], 300))->toThrow(DeliveryException::class)
        ->and($service->clientCalls)->toBe(0);
});

it('never propagates raw exception messages or chains', function () {
    $client = Mockery::mock(ClientInterface::class);
    $client->shouldReceive('sendRequest')->andThrow(new RuntimeException('secret-endpoint secret-auth secret-vapid'));
    try {
        (new WebPushFixtureDelivery($client))->send($this->subscription, [], 300);
        $this->fail('Expected sanitized failure');
    } catch (DeliveryException $e) {
        expect((string) $e)->toBe('Web Push: invalid_subscription_or_configuration')
            ->and($e->getPrevious())->toBeNull();
    }
});

it('rejects mismatched VAPID pairs before constructing a client', function () {
    $other = VAPID::createVapidKeys();
    config(['webpush.vapid.private_key' => $other['privateKey']]);
    $service = new WebPushFixtureDelivery(new WebPushResponseClient(201));
    expect(fn () => $service->validateConfiguration())->toThrow(DeliveryException::class, 'invalid_vapid_configuration')
        ->and($service->clientCalls)->toBe(0);
});

it('preserves sanitized transport retry decisions', function () {
    $client = Mockery::mock(ClientInterface::class);
    $client->shouldReceive('sendRequest')->andThrow(new DeliveryException('network_temporary', true));
    try {
        (new WebPushFixtureDelivery($client))->send($this->subscription, [], 300);
        $this->fail('Expected retryable failure');
    } catch (DeliveryException $e) {
        expect($e->retryable)->toBeTrue()->and((string) $e)->toBe('Web Push: network_temporary');
    }
});

it('uses the canonical hostname for the request and VAPID audience with stored legacy encoding', function () {
    $this->subscription->endpoint = 'HTTPS://BÜCHER.de:443/opaque%2ftoken?x=%2F';
    $this->subscription->content_encoding = ContentEncoding::aesgcm;
    $client = new WebPushResponseClient(201);
    (new WebPushFixtureDelivery($client))->send($this->subscription, [], 300);
    expect((string) $client->request->getUri())->toBe('https://xn--bcher-kva.de/opaque%2ftoken?x=%2F')
        ->and($client->request->getHeaderLine('Host'))->toBe('xn--bcher-kva.de')
        ->and($client->request->getHeaderLine('Content-Encoding'))->toBe('aesgcm');
    $authorization = $client->request->getHeaderLine('Authorization');
    preg_match('/(?:WebPush |t=)([^, ]+)/', $authorization, $matches);
    $claims = json_decode(base64_decode(strtr(explode('.', $matches[1])[1], '-_', '+/')), true);
    expect($claims['aud'])->toBe('https://xn--bcher-kva.de');
});

it('removes sensitive vendor stack arguments even when PHP trace arguments are enabled', function () {
    $previous = ini_set('zend.exception_ignore_args', '0');
    $client = new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            // This exception has a live request in its trace. Delivery must replace it.
            throw new DeliveryException('network_temporary', true);
        }
    };
    try {
        (new WebPushFixtureDelivery($client))->send($this->subscription, [], 300);
        $this->fail('Expected failure');
    } catch (DeliveryException $e) {
        $trace = json_encode($e->getTrace()).$e->getTraceAsString().(string) $e;
        expect($trace)->not->toContain('secret-endpoint', $this->subscription->public_key, $this->subscription->auth_token, config('webpush.vapid.private_key'), 'Authorization')
            ->and($e->getPrevious())->toBeNull()->and($e->retryable)->toBeTrue();
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
});

<?php

use App\Services\WebPush\BoundedDnsResolver;
use App\Services\WebPush\DeliveryException;
use App\Services\WebPush\EndpointPolicy;
use App\Services\WebPush\PinnedHttpClient;
use App\Services\WebPush\PublicAddressPolicy;
use App\Services\WebPush\SingleRequestCurlFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\Process\Process;

class FixtureAddressPolicy extends PublicAddressPolicy
{
    public function assertPublic(string $ip, array $extraBlocked = []): void
    {
        if (! in_array($ip, ['127.0.0.1', '::1'], true)) {
            parent::assertPublic($ip, $extraBlocked);
        }
    }
}

class FixturePinnedClient extends PinnedHttpClient
{
    public array $options = [];

    public function __construct(private string $ca, private int $port, private bool $badOption = false, string $endpoint = 'https://push.example.com/token', private string $ip = '127.0.0.1', ?BoundedDnsResolver $resolver = null)
    {
        $resolver ??= new class($ip) extends BoundedDnsResolver
        {
            public function __construct(private string $ip) {}

            public function resolve(string $host, float $timeout = 2, array $blocked = []): array
            {
                return [$this->ip];
            }
        };
        // Only the test fixture admits loopback. Production always uses PublicAddressPolicy.
        parent::__construct($endpoint, $resolver, addressPolicy: new FixtureAddressPolicy);
    }

    protected function createClient(array $options): Client
    {
        $this->options = $options;
        $target = str_contains($this->ip, ':') ? '['.$this->ip.']' : $this->ip;
        expect($options['handler'])->toBeInstanceOf(CurlHandler::class)
            ->and($options['curl'][CURLOPT_CONNECT_TO])->toBe(['::'.$target.':443'])
            ->and($options['curl'][CURLOPT_FRESH_CONNECT])->toBeTrue()
            ->and($options['curl'][CURLOPT_FORBID_REUSE])->toBeTrue()
            ->and($options['verify'])->toBeTrue()
            ->and($options['allow_redirects'])->toBeFalse()
            ->and($options['proxy'])->toBe('');
        // Use an unprivileged fixture port and its private test CA, retaining real cURL/TLS.
        // The production routing rule above is asserted before these fixture-only changes.
        $options['curl'][CURLOPT_CONNECT_TO] = ['::'.$target.':'.$this->port];
        // Simulate DNS changing after validation. This conflicting cache entry must be ignored.
        $options['curl'][CURLOPT_RESOLVE] = ['push.example.com:443:127.0.0.2'];
        $options['verify'] = $this->ca;
        if (isset($options['curl'][CURLOPT_PREREQFUNCTION])) {
            $check = $options['curl'][CURLOPT_PREREQFUNCTION];
            $port = $this->port;
            $options['curl'][CURLOPT_PREREQFUNCTION] = static fn ($handle, $peer, $local, $remotePort, $localPort) => $remotePort === $port ? $check($handle, $peer, $local, 443, $localPort) : CURL_PREREQFUNC_ABORT;
        }
        if ($this->badOption) {
            // A supported option with an invalid value fails in CurlFactory before curl_exec.
            $options['curl'][CURLOPT_CONNECT_TO] = new stdClass;
        }

        return parent::createClient($options);
    }
}

function localTlsFixture(int $status = 201, int $count = 1, string $ip = '127.0.0.1', string $mode = 'normal'): array
{
    $dir = sys_get_temp_dir().'/webpush-tls-'.bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $cert = new Process(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=push.example.com', '-addext', 'subjectAltName=DNS:push.example.com,DNS:xn--bcher-kva.de', '-days', '1', '-keyout', $dir.'/key.pem', '-out', $dir.'/cert.pem']);
    $cert->mustRun();
    $server = new Process(['python3', dirname(__DIR__, 2).'/Fixtures/WebPush/tls-server.py', $dir.'/cert.pem', $dir.'/key.pem', (string) $status, (string) $count, $ip, $mode]);
    $server->setTimeout(8);
    $server->start();
    $server->waitUntil(fn ($type, $data) => str_contains($data, '"port"'));
    $first = strtok($server->getOutput(), "\n");
    if (! $first) {
        $server->stop(0);
        $error = $server->getErrorOutput();
        closeTlsFixture($server, $dir);
        throw new RuntimeException('Local TLS fixture failed to bind: '.$error);
    }

    return [$server, $dir, json_decode($first, true)['port']];
}

function closeTlsFixture(Process $server, string $dir): void
{
    $server->stop(0);
    unlink($dir.'/key.pem');
    unlink($dir.'/cert.pem');
    rmdir($dir);
}

it('uses the numeric destination while retaining TLS SNI and Host, ignoring environment proxies', function () {
    [$server, $dir, $port] = localTlsFixture(201, 2);
    $names = ['HTTPS_PROXY', 'HTTP_PROXY', 'ALL_PROXY', 'https_proxy', 'http_proxy', 'all_proxy', 'NO_PROXY', 'no_proxy'];
    $saved = [];
    foreach ($names as $name) {
        $saved[$name] = getenv($name);
        putenv($name.'='.(strtolower($name) === 'no_proxy' ? '' : 'http://127.0.0.1:1'));
    }
    try {
        $request = new Request('POST', 'https://push.example.com/token', ['Content-Length' => '4'], 'test');
        $first = new FixturePinnedClient($dir.'/cert.pem', $port);
        expect($first->sendRequest($request)->getStatusCode())->toBe(201);
        expect(fn () => $first->sendRequest($request))->toThrow(DeliveryException::class, 'transport_already_used');
        $second = new FixturePinnedClient($dir.'/cert.pem', $port);
        expect($second->sendRequest($request)->getStatusCode())->toBe(201);
        $server->wait();
        expect($server->getExitCode())->toBe(0);
        $lines = array_map(fn ($line) => json_decode($line, true), explode("\n", trim($server->getOutput())));
        $requests = array_values(array_filter($lines, fn ($line) => isset($line['request'])));
        expect($requests)->toHaveCount(2)
            ->and($requests[0])->toBe(['host' => 'push.example.com', 'sni' => 'push.example.com', 'request' => 'POST /token HTTP/1.1'])
            ->and(substr_count($server->getOutput(), '"closed": true'))->toBe(2);
    } finally {
        foreach ($saved as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
        closeTlsFixture($server, $dir);
    }
});

it('returns redirects without following them', function () {
    [$server, $dir, $port] = localTlsFixture(302);
    try {
        $client = new FixturePinnedClient($dir.'/cert.pem', $port);
        expect($client->sendRequest(new Request('POST', 'https://push.example.com/token'))->getStatusCode())->toBe(302);
        $server->wait();
        expect(substr_count($server->getOutput(), '"request"'))->toBe(1);
    } finally {
        closeTlsFixture($server, $dir);
    }
});

it('rejects the wrong certificate hostname before any HTTP request', function () {
    [$server, $dir, $port] = localTlsFixture();
    try {
        $client = new FixturePinnedClient($dir.'/cert.pem', $port, endpoint: 'https://wrong.example.com/token');
        expect(fn () => $client->sendRequest(new Request('POST', 'https://wrong.example.com/token')))->toThrow(DeliveryException::class);
        $server->wait();
        expect($server->getOutput())->not->toContain('"request"');
    } finally {
        closeTlsFixture($server, $dir);
    }
});

it('makes no connection when required curl option application fails', function () {
    [$server, $dir, $port] = localTlsFixture(mode: 'observe');
    try {
        $client = new FixturePinnedClient($dir.'/cert.pem', $port, true);
        expect(fn () => $client->sendRequest(new Request('POST', 'https://push.example.com/token')))->toThrow(DeliveryException::class, 'transport_failure');
        $server->wait();
        expect($server->getOutput())->not->toContain('"accepted"');
    } finally {
        closeTlsFixture($server, $dir);
    }
});

it('rejects mixed DNS answers before creating any client', function () {
    $resolver = new class extends BoundedDnsResolver
    {
        public function resolve(string $host, float $timeout = 2, array $blocked = []): array
        {
            return ['8.8.8.8', '127.0.0.1'];
        }
    };
    $client = new PinnedHttpClient('https://push.example.com/token', $resolver);
    expect(fn () => $client->sendRequest(new Request('POST', 'https://push.example.com/token')))->toThrow(DeliveryException::class, 'unsafe_destination');
});

it('formats IPv6 pinning and checks the peer before transmission', function () {
    $resolver = new class extends BoundedDnsResolver
    {
        public function resolve(string $host, float $timeout = 2, array $blocked = []): array
        {
            return ['2606:4700:4700::1111'];
        }
    };
    $client = new class('https://push.example.com/token', $resolver) extends PinnedHttpClient
    {
        protected function createClient(array $options): Client
        {
            expect($options['curl'][CURLOPT_CONNECT_TO])->toBe(['::[2606:4700:4700::1111]:443']);
            if (isset($options['curl'][CURLOPT_PREREQFUNCTION])) {
                $check = $options['curl'][CURLOPT_PREREQFUNCTION];
                expect($check(null, '::1', '', 443, 0))->toBe(CURL_PREREQFUNC_ABORT)
                    ->and($check(null, '2606:4700:4700::1111', '', 443, 0))->toBe(CURL_PREREQFUNC_OK);
            }
            // Inspect real options without connecting to an external host.
            throw new RuntimeException('fixture_stop');
        }
    };
    expect(fn () => $client->sendRequest(new Request('POST', 'https://push.example.com/token')))->toThrow(DeliveryException::class);
});

it('distinguishes transient cURL failures from certificate or capability failures', function (int $errno, bool $retryable) {
    $resolver = new class extends BoundedDnsResolver
    {
        public function resolve(string $host, float $timeout = 2, array $blocked = []): array
        {
            return ['8.8.8.8'];
        }
    };
    $client = new class('https://push.example.com/token', $resolver) extends PinnedHttpClient
    {
        public int $errno;

        protected function createClient(array $options): Client
        {
            return new Client(['handler' => new MockHandler([
                new ConnectException('secret-endpoint auth-token', new Request('POST', 'https://push.example.com/token'), null, ['errno' => $this->errno]),
            ])]);
        }
    };
    $client->errno = $errno;
    try {
        $client->sendRequest(new Request('POST', 'https://push.example.com/token'));
        $this->fail('Expected failure');
    } catch (DeliveryException $e) {
        expect($e->retryable)->toBe($retryable)->and((string) $e)->not->toContain('secret-endpoint', 'auth-token')
            ->and($e->getPrevious())->toBeNull();
    }
})->with([[CURLE_COULDNT_CONNECT, true], [CURLE_OPERATION_TIMEDOUT, true], [CURLE_PARTIAL_FILE, true], [60, false], [48, false]]);

it('pins an actual IPv6 socket while preserving the canonical IDN hostname and opaque tokens', function () {
    [$server, $dir, $port] = localTlsFixture(ip: '::1');
    try {
        $endpoint = (new EndpointPolicy)->canonicalize('HTTPS://BÜCHER.de:443/a%2fb//c?x=%2F&x=+');
        $client = new FixturePinnedClient($dir.'/cert.pem', $port, endpoint: $endpoint, ip: '::1');
        expect($client->sendRequest(new Request('POST', $endpoint))->getStatusCode())->toBe(201);
        $server->wait();
        expect($server->getOutput())->toContain('"sni": "xn--bcher-kva.de"', '"host": "xn--bcher-kva.de"', 'POST /a%2Fb//c?x=%2F&x=+ HTTP/1.1');
    } finally {
        closeTlsFixture($server, $dir);
    }
});

it('bounds real provider response bodies without leaking their contents', function () {
    [$server, $dir, $port] = localTlsFixture(mode: 'large');
    try {
        $client = new FixturePinnedClient($dir.'/cert.pem', $port);
        try {
            $client->sendRequest(new Request('POST', 'https://push.example.com/token'));
            $this->fail('Expected response limit');
        } catch (DeliveryException $e) {
            expect((string) $e)->toBe('Web Push: response_too_large')
                ->and($e->getPrevious())->toBeNull();
        }
        $server->wait();
        expect(substr_count($server->getOutput(), '"accepted"'))->toBe(1);
    } finally {
        closeTlsFixture($server, $dir);
    }
});

it('refuses the real Guzzle internal retry path before another socket can be opened', function () {
    [$server, $dir, $port] = localTlsFixture(mode: 'observe');
    try {
        $factory = new SingleRequestCurlFactory;
        $handler = new CurlHandler(['handle_factory' => $factory]);
        $easy = $factory->create(new Request('POST', 'https://push.example.com/token'), [
            'verify' => $dir.'/cert.pem', 'proxy' => '',
            'curl' => [CURLOPT_CONNECT_TO => ['::127.0.0.1:'.$port]],
        ]);
        // Exercise finishError/retryFailedRewind in the installed Guzzle, not a mock.
        $easy->errno = 65;
        expect(fn () => CurlFactory::finish($handler, $easy, $factory)->wait())
            ->toThrow(DeliveryException::class, 'transport_retry_refused');
        $server->wait();
        expect($server->getOutput())->not->toContain('"accepted"');
    } finally {
        closeTlsFixture($server, $dir);
    }
});

it('makes no connection for unsafe DNS answers even with a listening local target', function () {
    [$server, $dir, $port] = localTlsFixture(mode: 'observe');
    try {
        $resolver = new class extends BoundedDnsResolver
        {
            public function resolve(string $host, float $timeout = 2, array $blocked = []): array
            {
                return ['127.0.0.1', '169.254.169.254'];
            }
        };
        $client = new FixturePinnedClient($dir.'/cert.pem', $port, resolver: $resolver);
        expect(fn () => $client->sendRequest(new Request('POST', 'https://push.example.com/token')))
            ->toThrow(DeliveryException::class, 'unsafe_destination');
        $server->wait();
        expect($server->getOutput())->not->toContain('"accepted"')->and($client->options)->toBe([]);
    } finally {
        closeTlsFixture($server, $dir);
    }
});

it('rejects unbounded or sub-millisecond transport timeouts before resolving', function (float $connect, float $timeout) {
    $resolver = Mockery::mock(BoundedDnsResolver::class);
    $resolver->shouldNotReceive('resolve');
    $client = new PinnedHttpClient('https://push.example.com/token', $resolver, ['connect_timeout' => $connect, 'request_timeout' => $timeout]);
    expect(fn () => $client->sendRequest(new Request('POST', 'https://push.example.com/token')))
        ->toThrow(DeliveryException::class, 'invalid_transport_configuration');
})->with([[NAN, 10.0], [3.0, NAN], [INF, INF], [0.0001, 10.0], [3.001, 10.0], [3.0, 10.001], [3.0, 2.0]]);

it('fails before networking when a required cURL function is disabled', function () {
    $autoload = dirname(__DIR__, 3).'/vendor/autoload.php';
    $code = 'require '.var_export($autoload, true).';'
        .'try { App\\Services\\WebPush\\PinnedHttpClient::assertAvailable(); exit(1); }'
        .'catch (App\\Services\\WebPush\\DeliveryException $e) { echo $e->category; }';
    $process = new Process([PHP_BINARY, '-d', 'disable_functions=curl_setopt', '-r', $code]);
    $process->setTimeout(2);
    $process->run();
    expect($process->getExitCode())->toBe(0)->and($process->getOutput())->toBe('transport_unavailable');
});

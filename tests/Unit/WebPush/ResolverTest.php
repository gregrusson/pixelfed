<?php

use App\Services\WebPush\BoundedDnsResolver;
use App\Services\WebPush\DeliveryException;
use App\Services\WebPush\DnsLookup;
use Symfony\Component\Process\Process;

function fixtureResolver(string $code): BoundedDnsResolver
{
    return new class($code) extends BoundedDnsResolver
    {
        public ?Process $child = null;

        public function __construct(private string $code) {}

        protected function process(string $host): Process
        {
            return $this->child = new Process([PHP_BINARY, '-r', $this->code]);
        }
    };
}

it('collects and deduplicates validated addresses from an isolated process', function () {
    $resolver = fixtureResolver('echo json_encode(["addresses" => ["8.8.8.8", "2606:4700::1111", "8.8.8.8"]]);');
    expect($resolver->resolve('push.example.com'))->toBe(['8.8.8.8', '2606:4700::1111'])
        ->and($resolver->child->isRunning())->toBeFalse();
});

it('terminates a stalled child within the overall deadline', function () {
    $resolver = fixtureResolver('if (function_exists("pcntl_signal")) { pcntl_async_signals(true); pcntl_signal(SIGTERM, SIG_IGN); } sleep(30);');
    $start = microtime(true);
    try {
        $resolver->resolve('push.example.com', 0.15);
        test()->fail('Expected timeout');
    } catch (DeliveryException $e) {
        expect($e->category)->toBe('dns_timeout')->and($e->retryable)->toBeTrue();
    }
    expect(microtime(true) - $start)->toBeLessThan(1.5)
        ->and($resolver->child->isRunning())->toBeFalse();
});

it('rejects incomplete, excessive, malformed and mixed answers', function (string $code) {
    expect(fn () => fixtureResolver($code)->resolve('push.example.com'))->toThrow(DeliveryException::class);
})->with([
    'echo "no-json";', 'echo "{}";', 'echo json_encode(["addresses"=>[]]);',
    'echo json_encode(["addresses"=>["8.8.8.8","127.0.0.1"]]);',
    'echo json_encode(["addresses"=>array_fill(0,33,"8.8.8.8")]);',
    'echo json_encode(["addresses"=>[42]]);', 'echo json_encode(["addresses"=>["bogus"]]);',
    'echo json_encode(["addresses"=>["8.8.8.8"],"error"=>true]);',
    'echo json_encode(["addresses"=>["8.8.8.8"]]); exit(1);',
    'fwrite(STDERR,"error");', 'echo str_repeat("x", 5000);',
]);

it('collects both address families through a bounded CNAME chain', function () {
    $result = (new DnsLookup)->lookup('push.example.com', function ($host, $type) {
        if ($host === 'push.example.com.') {
            return $type === DNS_CNAME ? [['type' => 'CNAME', 'target' => 'edge.example.com.']] : [];
        }

        return match ($type) {
            DNS_A => [['type' => 'A', 'ip' => '8.8.8.8']],
            DNS_AAAA => [['type' => 'AAAA', 'ipv6' => '2606:4700::1111']],
            default => [],
        };
    });
    expect($result)->toBe(['8.8.8.8', '2606:4700::1111']);
});

it('rejects CNAME loops and DNS errors', function () {
    expect(fn () => (new DnsLookup)->lookup('push.example.com', fn () => [['type' => 'CNAME', 'target' => 'push.example.com']]))
        ->toThrow(DeliveryException::class)
        ->and(fn () => (new DnsLookup)->lookup('push.example.com', fn () => false))->toThrow(DeliveryException::class);
});

it('executes the real helper entrypoint without bootstrapping Laravel', function () {
    $resolver = new class extends BoundedDnsResolver
    {
        protected function process(string $host): Process
        {
            return new Process([
                PHP_BINARY, '-d', 'auto_prepend_file='.dirname(__DIR__, 2).'/Fixtures/WebPush/dns-prepend.php',
                dirname(__DIR__, 3).'/app/Services/WebPush/resolve-host.php', $host,
            ]);
        }
    };
    expect($resolver->resolve('push.example.com'))->toBe(['8.8.8.8', '2606:4700:4700::1111']);
});

it('rejects non-finite and weakened DNS deadlines before starting a child', function (float $timeout) {
    $resolver = fixtureResolver('throw new RuntimeException("must not execute");');
    expect(fn () => $resolver->resolve('push.example.com', $timeout))->toThrow(DeliveryException::class, 'invalid_dns_configuration')
        ->and($resolver->child)->toBeNull();
})->with([NAN, INF, -INF, 0.0, 0.0001, 2.001]);

it('preserves fixed DNS failure categories without trusting arbitrary helper text', function (string $category, bool $retryable) {
    $resolver = fixtureResolver('echo '.var_export(json_encode(['error' => $category]), true).';');
    try {
        $resolver->resolve('push.example.com');
        $this->fail('Expected failure');
    } catch (DeliveryException $e) {
        expect($e->retryable)->toBe($retryable)
            ->and((string) $e)->not->toContain('sensitive-input');
    }
})->with([['dns_error', true], ['dns_empty', true], ['dns_chain_error', false], ['dns_excessive', false], ['invalid_endpoint', false], ['sensitive-input', false]]);

it('kills and reaps a child that ignores SIGTERM', function () {
    $pidFile = tempnam(sys_get_temp_dir(), 'webpush-pid-');
    $resolver = fixtureResolver('pcntl_async_signals(true); pcntl_signal(SIGTERM, SIG_IGN); file_put_contents('.var_export($pidFile, true).', getmypid()); sleep(30);');
    expect(fn () => $resolver->resolve('push.example.com', 0.2))->toThrow(DeliveryException::class, 'dns_timeout');
    $pid = (int) file_get_contents($pidFile);
    unlink($pidFile);
    expect($pid)->toBeGreaterThan(0)
        ->and($resolver->child->isRunning())->toBeFalse()
        ->and(posix_kill($pid, 0))->toBeFalse();
});

it('bounds a flooding child output and terminates it without leaking stderr', function () {
    $resolver = fixtureResolver('while (true) { fwrite(STDOUT, str_repeat("x", 4096)); }');
    $start = microtime(true);
    expect(fn () => $resolver->resolve('push.example.com'))->toThrow(DeliveryException::class, 'dns_invalid_output');
    expect(microtime(true) - $start)->toBeLessThan(1.5)
        ->and($resolver->child->isRunning())->toBeFalse();
    $resolver = fixtureResolver('fwrite(STDERR, "secret-endpoint auth-token");');
    try {
        $resolver->resolve('push.example.com');
        $this->fail('Expected error');
    } catch (DeliveryException $e) {
        expect((string) $e)->toBe('Web Push: dns_invalid_output')->and($e->getPrevious())->toBeNull();
    }
});

it('rejects over-depth CNAME chains without completing the lookup', function () {
    $calls = 0;
    expect(fn () => (new DnsLookup)->lookup('push.example.com', function ($name, $type) use (&$calls) {
        $calls++;

        return $type === DNS_CNAME ? [['type' => 'CNAME', 'target' => 'next'.$calls.'.example.com']] : [];
    }))->toThrow(DeliveryException::class, 'dns_chain_error');
    expect($calls)->toBeLessThanOrEqual(24);
});

it('rejects malformed CNAME targets and excessive raw DNS records permanently', function () {
    expect(fn () => (new DnsLookup)->lookup('push.example.com', fn () => [['type' => 'CNAME', 'target' => 'edge.example.com..']]))
        ->toThrow(DeliveryException::class, 'invalid_endpoint');
    try {
        (new DnsLookup)->lookup('push.example.com', fn () => array_fill(0, 65, ['type' => 'A', 'ip' => '8.8.8.8']));
        $this->fail('Expected limit');
    } catch (DeliveryException $e) {
        expect($e->category)->toBe('dns_excessive')->and($e->retryable)->toBeFalse();
    }
});

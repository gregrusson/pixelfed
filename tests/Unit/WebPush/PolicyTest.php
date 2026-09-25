<?php

use App\Services\WebPush\DeliveryException;
use App\Services\WebPush\EndpointPolicy;
use App\Services\WebPush\PublicAddressPolicy;

it('canonicalizes HTTPS and IDNA without rewriting opaque path and query tokens', function () {
    $policy = new EndpointPolicy;
    expect($policy->canonicalize('HTTPS://BÜCHER.de:443/a%2fb//c?x=%2F&x=+'))
        ->toBe('https://xn--bcher-kva.de/a%2fb//c?x=%2F&x=+')
        ->and($policy->canonicalize('https://xn--bcher-kva.de/?'))->toBe('https://xn--bcher-kva.de/?');
});

it('rejects ambiguous and unsafe endpoints', function (string $url) {
    expect(fn () => (new EndpointPolicy)->canonicalize($url))->toThrow(DeliveryException::class);
})->with([
    'http://push.example.com/a', '//push.example.com/a', 'https://u:p@push.example.com/a',
    'https://@push.example.com/', 'https://:@push.example.com/', 'https://push.example.com/#',
    'https://push.example.com:80/', 'https://push.example.com:0443/', 'https://push.example.com:/',
    'https://push.example.com:443:443/', "https://push.example.com/\n", 'https://push.example.com\\evil/',
    'https://localhost/', 'https://foo.localhost/', 'https://a.local/', 'https://a.home.arpa/',
    'https://127.0.0.1/', 'https://2130706433/', 'https://0177.0.0.1/', 'https://0x7f000001/',
    'https://127.1/', 'https://a.0x7f/', 'https://[::1]/', 'https://[fe80::1%25eth0]/',
    'https://foo..com/', 'https://-foo.com/', 'https://foo_.com/', 'https://foo.com./',
    'https://xn--.com/', "https://\xff.com/", 'https://%65xample.com/',
    'https://push.example.com/%no', 'https://push.example.com/ has-space',
]);

it('blocks special and transition networks', function (string $ip) {
    expect(fn () => (new PublicAddressPolicy)->assertPublic($ip))->toThrow(DeliveryException::class);
})->with([
    '0.0.0.0', '0.255.255.255', '10.0.0.1', '127.255.255.255', '169.254.169.254',
    '172.16.0.0', '172.31.255.255', '192.168.255.255', '100.64.0.0', '100.127.255.255',
    '192.0.0.9', '192.0.2.1', '198.18.0.1', '198.51.100.1', '203.0.113.1',
    '224.0.0.0', '239.255.255.255', '240.0.0.0', '255.255.255.255',
    '::', '::1', '::ffff:8.8.8.8', '::ffff:127.0.0.1', '64:ff9b::808:808',
    '64:ff9b::a9fe:a9fe', '64:ff9b:1::1', '2002:7f00:1::1', '2002:808:808::1',
    '2001::1', '2001:db8::1', '3fff::1', 'fc00::1', 'fe80::1', 'fec0::1', 'ff02::1',
    'not-an-ip', '127.1', '0127.0.0.1',
]);

it('accepts ordinary public addresses and CIDR neighbors', function (string $ip) {
    (new PublicAddressPolicy)->assertPublic($ip);
    expect(true)->toBeTrue();
})->with(['8.8.8.8', '1.1.1.1', '100.63.255.255', '100.128.0.0', '172.15.255.255', '172.32.0.0', '2606:4700:4700::1111', '2001:4860:4860::8888']);

it('enforces extra CIDRs and fails closed on malformed configuration', function () {
    $policy = new PublicAddressPolicy;
    expect(fn () => $policy->assertPublic('8.8.8.8', ['8.8.8.8/32']))->toThrow(DeliveryException::class)
        ->and(fn () => $policy->assertPublic('2606:4700::1', ['2606:4700::/33']))->toThrow(DeliveryException::class)
        ->and(fn () => $policy->assertPublic('8.8.8.8', ['bad']))->toThrow(DeliveryException::class)
        ->and(fn () => $policy->assertPublic('8.8.8.8', ['1.1.1.1/33']))->toThrow(DeliveryException::class);
    $policy->assertPublic('8.8.8.9', ['8.8.8.8/32']);
});

it('checks both ends and immediate neighbors of every built-in allocation', function (string $ip, bool $allowed) {
    if ($allowed) {
        (new PublicAddressPolicy)->assertPublic($ip);
        expect(true)->toBeTrue();
    } else {
        expect(fn () => (new PublicAddressPolicy)->assertPublic($ip))->toThrow(DeliveryException::class, 'unsafe_destination');
    }
})->with([
    // 0.0.0.0/8
    ['0.0.0.0', false],
    ['0.255.255.255', false],
    ['1.0.0.0', true],
    // 10.0.0.0/8
    ['9.255.255.255', true],
    ['10.0.0.0', false],
    ['10.255.255.255', false],
    ['11.0.0.0', true],
    // 100.64.0.0/10
    ['100.63.255.255', true],
    ['100.64.0.0', false],
    ['100.127.255.255', false],
    ['100.128.0.0', true],
    // 127.0.0.0/8
    ['126.255.255.255', true],
    ['127.0.0.0', false],
    ['127.255.255.255', false],
    ['128.0.0.0', true],
    // 169.254.0.0/16
    ['169.253.255.255', true],
    ['169.254.0.0', false],
    ['169.254.255.255', false],
    ['169.255.0.0', true],
    // 172.16.0.0/12
    ['172.15.255.255', true],
    ['172.16.0.0', false],
    ['172.31.255.255', false],
    ['172.32.0.0', true],
    // 192.0.0.0/24
    ['191.255.255.255', true],
    ['192.0.0.0', false],
    ['192.0.0.255', false],
    ['192.0.1.0', true],
    // 192.0.2.0/24
    ['192.0.1.255', true],
    ['192.0.2.0', false],
    ['192.0.2.255', false],
    ['192.0.3.0', true],
    // 192.31.196.0/24
    ['192.31.195.255', true],
    ['192.31.196.0', false],
    ['192.31.196.255', false],
    ['192.31.197.0', true],
    // 192.52.193.0/24
    ['192.52.192.255', true],
    ['192.52.193.0', false],
    ['192.52.193.255', false],
    ['192.52.194.0', true],
    // 192.88.99.0/24
    ['192.88.98.255', true],
    ['192.88.99.0', false],
    ['192.88.99.255', false],
    ['192.88.100.0', true],
    // 192.168.0.0/16
    ['192.167.255.255', true],
    ['192.168.0.0', false],
    ['192.168.255.255', false],
    ['192.169.0.0', true],
    // 192.175.48.0/24
    ['192.175.47.255', true],
    ['192.175.48.0', false],
    ['192.175.48.255', false],
    ['192.175.49.0', true],
    // 198.18.0.0/15
    ['198.17.255.255', true],
    ['198.18.0.0', false],
    ['198.19.255.255', false],
    ['198.20.0.0', true],
    // 198.51.100.0/24
    ['198.51.99.255', true],
    ['198.51.100.0', false],
    ['198.51.100.255', false],
    ['198.51.101.0', true],
    // 203.0.113.0/24
    ['203.0.112.255', true],
    ['203.0.113.0', false],
    ['203.0.113.255', false],
    ['203.0.114.0', true],
    // 224.0.0.0/4
    ['223.255.255.255', true],
    ['224.0.0.0', false],
    ['239.255.255.255', false],
    ['240.0.0.0', false],
    // 240.0.0.0/4
    ['255.255.255.255', false],
    // 2001::/23
    ['2000:ffff:ffff:ffff:ffff:ffff:ffff:ffff', true],
    ['2001::', false],
    ['2001:1ff:ffff:ffff:ffff:ffff:ffff:ffff', false],
    ['2001:200::', true],
    // 2001:db8::/32
    ['2001:db7:ffff:ffff:ffff:ffff:ffff:ffff', true],
    ['2001:db8::', false],
    ['2001:db8:ffff:ffff:ffff:ffff:ffff:ffff', false],
    ['2001:db9::', true],
    // 2002::/16
    ['2001:ffff:ffff:ffff:ffff:ffff:ffff:ffff', true],
    ['2002::', false],
    ['2002:ffff:ffff:ffff:ffff:ffff:ffff:ffff', false],
    ['2003::', true],
    // 2620:4f:8000::/48
    ['2620:4f:7fff:ffff:ffff:ffff:ffff:ffff', true],
    ['2620:4f:8000::', false],
    ['2620:4f:8000:ffff:ffff:ffff:ffff:ffff', false],
    ['2620:4f:8001::', true],
    // 3fff::/20
    ['3ffe:ffff:ffff:ffff:ffff:ffff:ffff:ffff', true],
    ['3fff::', false],
    ['3fff:fff:ffff:ffff:ffff:ffff:ffff:ffff', false],
    ['3fff:1000::', true],
    // 2000::/3
    ['1fff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', false],
    ['2000::', true],
    ['3fff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', true],
    ['4000::', false],
]);

it('rejects authority parser and IDNA edge cases before resolution', function (string $url) {
    expect(fn () => (new EndpointPolicy)->canonicalize($url))->toThrow(DeliveryException::class);
})->with([
    'https:///push.example.com/', 'https://push.example.com\\@other.example.com/',
    'https://push.example.com%2f.other.example.com/', 'https://push.example.com%00.other.example.com/',
    'https://push.example.com%3a443/', 'https://user＠push.example.com/',
    'https://[2001:4860::8888]/', 'https://2001:4860::8888/', 'https://[::1/',
    'https://foo.com。/', 'https://a.127/', 'https://0x7f.0x0.0x0.0x1/',
    'https://0177.1/', 'https://127.0.1/', 'https://a.localdomain/', 'https://a.internal/',
    'https://a.lan/', 'https://a.onion/', 'https://xn--a.com/',
    "https://a\u{200D}.com/", "https://abc\u{05D0}.com/", "https://push.example.com/\x7f",
]);

it('cannot weaken built-in blocks with administrator networks', function () {
    expect(fn () => (new PublicAddressPolicy)->assertPublic('127.0.0.1', ['8.8.8.8/32']))
        ->toThrow(DeliveryException::class, 'unsafe_destination')
        ->and(fn () => (new PublicAddressPolicy)->assertPublic('127.0.0.1', ['invalid']))
        ->toThrow(DeliveryException::class, 'invalid_blocked_network');
});

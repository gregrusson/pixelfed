<?php

namespace App\Services\WebPush;

// Fixture-only replacement inside the isolated helper, avoiding external DNS in tests.
function dns_get_record(string $host, int $type): array
{
    if ($host !== 'push.example.com.') {
        return [];
    }

    return match ($type) {
        DNS_A => [['type' => 'A', 'ip' => '8.8.8.8']],
        DNS_AAAA => [['type' => 'AAAA', 'ipv6' => '2606:4700:4700::1111']],
        default => [],
    };
}

<?php

namespace App\Services\WebPush;

/** Runs ONLY in the isolated helper. The parent enforces the total wall-clock deadline. */
class DnsLookup
{
    public function lookup(#[\SensitiveParameter] string $host, ?callable $query = null): array
    {
        $query ??= static fn ($name, $type) => dns_get_record($name, $type);
        $pending = [$host];
        $seen = [];
        $addresses = [];
        while ($pending !== []) {
            $name = array_shift($pending);
            if (isset($seen[$name]) || count($seen) >= 8) {
                throw new DeliveryException('dns_chain_error');
            }
            $seen[$name] = true;
            foreach ([DNS_A, DNS_AAAA, DNS_CNAME] as $type) {
                // Separate queries distinguish an absent record family from a failed query.
                $records = $query($name.'.', $type);
                if (! is_array($records)) {
                    throw new DeliveryException('dns_error', true);
                }
                if (count($records) > 64) {
                    throw new DeliveryException('dns_excessive');
                }
                foreach ($records as $record) {
                    if (($record['type'] ?? '') === 'CNAME') {
                        $target = $record['target'] ?? '';
                        $target = (new EndpointPolicy)->hostname(str_ends_with($target, '.') ? substr($target, 0, -1) : $target);
                        if (! in_array($target, $pending, true)) {
                            $pending[] = $target;
                        }
                    } elseif (in_array($record['type'] ?? '', ['A', 'AAAA'], true)) {
                        $ip = $record['ip'] ?? $record['ipv6'] ?? '';
                        if (@inet_pton($ip) === false) {
                            throw new DeliveryException('dns_error', true);
                        }
                        $ip = inet_ntop(inet_pton($ip));
                        $addresses[$ip] = $ip;
                    } else {
                        throw new DeliveryException('dns_error', true);
                    }
                }
                if (count($addresses) > 32 || count($pending) > 8) {
                    throw new DeliveryException('dns_excessive');
                }
            }
        }
        if ($addresses === []) {
            throw new DeliveryException('dns_empty', true);
        }

        return array_values($addresses);
    }
}

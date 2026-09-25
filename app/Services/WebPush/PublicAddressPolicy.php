<?php

namespace App\Services\WebPush;

class PublicAddressPolicy
{
    /**
     * Conservative snapshot of IANA IPv4/IPv6 Special-Purpose registries (2026-09).
     * https://www.iana.org/assignments/iana-ipv4-special-registry/
     * https://www.iana.org/assignments/iana-ipv6-special-registry/
     * Block whole special-purpose allocations, even their globally reachable exceptions.
     * IPv6 is restricted to ordinary 2000::/3 global unicast, then excludes special ranges.
     * Thus mapped/compatible, NAT64 (64:ff9b::/96, 64:ff9b:1::/48), ULA, site/link-local,
     * multicast and unspecified addresses cannot pass. No PHP private/reserved flags.
     * Administrators must also block their own translation/internal public-address ranges.
     */
    private const BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.31.196.0/24',
        '192.52.193.0/24', '192.88.99.0/24', '192.168.0.0/16', '192.175.48.0/24',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '2001::/23', '2001:db8::/32', '2002::/16', '2620:4f:8000::/48', '3fff::/20',
    ];

    public function assertPublic(string $ip, array $extraBlocked = []): void
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            throw new DeliveryException('invalid_address');
        }
        // Validate ALL configured entries even when an earlier entry already matches.
        $blocked = false;
        foreach (array_merge(self::BLOCKED, $extraBlocked) as $cidr) {
            $blocked = $this->contains($cidr, $binary) || $blocked;
        }
        if ($blocked || (strlen($binary) === 16 && ! $this->contains('2000::/3', $binary))) {
            throw new DeliveryException('unsafe_destination');
        }
    }

    private function contains(mixed $cidr, string $address): bool
    {
        if (! is_string($cidr) || ! preg_match('~\A([^/]+)/([0-9]{1,3})\z~D', $cidr, $parts)
            || ($network = @inet_pton($parts[1])) === false || (int) $parts[2] > strlen($network) * 8) {
            throw new DeliveryException('invalid_blocked_network');
        }
        if (strlen($network) !== strlen($address)) {
            return false;
        }
        $bits = (int) $parts[2];
        $bytes = intdiv($bits, 8);
        $remaining = $bits % 8;

        return substr($network, 0, $bytes) === substr($address, 0, $bytes)
            && ($remaining === 0 || ((ord($network[$bytes]) ^ ord($address[$bytes])) & (0xFF << (8 - $remaining))) === 0);
    }
}

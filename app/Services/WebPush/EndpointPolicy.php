<?php

namespace App\Services\WebPush;

class EndpointPolicy
{
    public function canonicalize(#[\SensitiveParameter] string $url): string
    {
        // Parse the authority ourselves: parse_url alone accepts ambiguous authorities.
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\#]/', $url)
            || ! preg_match('~\Ahttps://([^/?]+)([^#]*)\z~iD', $url, $parts)) {
            throw new DeliveryException('invalid_endpoint');
        }

        $authority = $parts[1];
        if (str_contains($authority, '@') || ! preg_match('/\A([^:]+)(?::443)?\z/D', $authority, $match)) {
            throw new DeliveryException('invalid_endpoint');
        }

        $host = $this->hostname($match[1]);
        $suffix = $parts[2];
        // Reject characters a URI implementation would silently re-encode. Keep opaque tokens intact.
        if (preg_match('/[^\x21-\x7e]/', $suffix) || preg_match('/%(?![a-f0-9]{2})/i', $suffix)
            || preg_match('/[<>"{}|^`]/', $suffix)) {
            throw new DeliveryException('invalid_endpoint');
        }

        return 'https://'.$host.$suffix;
    }

    public function hostname(#[\SensitiveParameter] string $host): string
    {
        if (! function_exists('idn_to_ascii')) {
            throw new DeliveryException('idna_unavailable');
        }
        $info = [];
        $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46, $info);
        if ($ascii === false || ($info['errors'] ?? 1) !== 0) {
            throw new DeliveryException('invalid_endpoint');
        }
        $ascii = strtolower($ascii);
        if (strlen($ascii) > 253 || ! preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $ascii)) {
            throw new DeliveryException('invalid_endpoint');
        }
        $last = substr($ascii, strrpos($ascii, '.') + 1);
        // Numeric final labels include inet_aton decimal/octal/hex shorthand.
        if (preg_match('/\A(?:[0-9]+|0x[a-f0-9]+)\z/iD', $last)) {
            throw new DeliveryException('invalid_endpoint');
        }
        foreach (['localhost', 'local', 'localdomain', 'internal', 'home', 'lan', 'test', 'invalid', 'example', 'onion', 'arpa'] as $suffix) {
            if ($ascii === $suffix || str_ends_with($ascii, '.'.$suffix)) {
                throw new DeliveryException('invalid_endpoint');
            }
        }

        return $ascii;
    }
}

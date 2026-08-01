<?php

namespace App\Support;

use RuntimeException;

class SafeProxyUrl
{
    public static function assert(string $url, array $blockedHosts = []): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('The proxy destination must be a valid HTTP or HTTPS URL.');
        }

        $blockedHosts = array_map(fn ($value) => strtolower(rtrim((string) $value, '.')), $blockedHosts);
        if (in_array($host, $blockedHosts, true) || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new RuntimeException('This proxy destination is not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            self::assertPublicIp($host);

            return;
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (! $records) {
            throw new RuntimeException('The proxy destination could not be resolved.');
        }

        foreach ($records as $record) {
            self::assertPublicIp($record['ip'] ?? $record['ipv6'] ?? '');
        }
    }

    private static function assertPublicIp(string $ip): void
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('Proxying to private or reserved network addresses is not allowed.');
        }
    }
}

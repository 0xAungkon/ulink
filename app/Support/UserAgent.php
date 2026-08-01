<?php

namespace App\Support;

class UserAgent
{
    public static function isBrowser(?string $ua): bool
    {
        $ua ??= '';

        return str_contains($ua, 'Mozilla/5.0')
            && preg_match('/(?:Chrome|CriOS|Firefox|FxiOS|Safari|Edg|OPR)\//i', $ua) === 1
            && preg_match('/bot|crawler|spider|curl|wget|postman|insomnia/i', $ua) !== 1;
    }

    public static function parse(?string $ua): array
    {
        $ua ??= '';
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Microsoft Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Unknown',
        };
        $device = match (true) {
            preg_match('/bot|crawler|spider/i', $ua) === 1 => 'Bot',
            str_contains($ua, 'iPad') || str_contains($ua, 'Tablet') => 'Tablet',
            preg_match('/Mobile|Android|iPhone/i', $ua) === 1 => 'Mobile',
            $ua === '' => 'Unknown',
            default => 'Desktop',
        };
        $operatingSystem = match (true) {
            preg_match('/iPhone|iPad|iPod/i', $ua) === 1 => 'iOS / iPadOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows NT') => 'Windows',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            $ua === '' => 'Unknown',
            default => 'Other',
        };

        return [$browser, $device, $operatingSystem];
    }
}

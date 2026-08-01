<?php

namespace App\Support;

class UserAgent
{
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

        return [$browser, $device];
    }
}

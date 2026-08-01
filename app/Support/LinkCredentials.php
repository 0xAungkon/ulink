<?php

namespace App\Support;

use App\Models\Link;
use Illuminate\Http\Request;

class LinkCredentials
{
    public static function hash(string $secret): string
    {
        return hash_hmac('sha256', $secret, (string) config('app.key'));
    }

    public static function valid(Link $link, string $secret): bool
    {
        return hash_equals($link->secret_hash, self::hash($secret));
    }

    public static function fromRequest(Request $request): array
    {
        $tokenId = (string) ($request->input('token_id') ?: $request->route('token_id'));
        $secret = (string) $request->input('secret_key', $request->input('secreat_key', ''));
        $credential = $request->bearerToken() ?: $request->cookie('ulink_token') ?: $request->header('X-Link-Token');

        if ($credential && str_contains($credential, ':')) {
            [$headerId, $headerSecret] = explode(':', $credential, 2);
            $tokenId = $tokenId ?: $headerId;
            $secret = $secret ?: $headerSecret;
        }

        return [$tokenId, $secret];
    }
}

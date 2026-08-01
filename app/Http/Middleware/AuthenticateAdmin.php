<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $username = (string) $request->getUser();
        $password = (string) $request->getPassword();
        $valid = hash_equals((string) config('ulink.admin_username'), $username)
            && hash_equals((string) config('ulink.admin_password'), $password);

        if (! $valid) {
            return response()->json(['message' => 'Invalid administrator credentials.'], 401, [
                'WWW-Authenticate' => 'Basic realm="ULink Admin"',
            ]);
        }

        return $next($request);
    }
}

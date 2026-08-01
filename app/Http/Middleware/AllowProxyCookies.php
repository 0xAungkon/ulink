<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AllowProxyCookies
{
    public function handle(Request $request, Closure $next): Response
    {
        foreach (array_keys($request->cookies->all()) as $name) {
            if (str_starts_with($name, 'ulink_up_')) {
                EncryptCookies::except($name);
            }
        }

        return $next($request);
    }
}

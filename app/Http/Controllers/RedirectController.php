<?php

namespace App\Http\Controllers;

use App\Models\Link;
use App\Support\UserAgent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectController extends Controller
{
    public function __invoke(Request $request, string $slug): RedirectResponse|Response
    {
        $link = Link::where('slug', $slug)->firstOrFail();
        [$browser, $device] = UserAgent::parse($request->userAgent());
        $expired = $link->expires_at->isPast();

        $link->visits()->create([
            'ip_address' => filter_var($request->header('CF-Connecting-IP'), FILTER_VALIDATE_IP)
                ? $request->header('CF-Connecting-IP')
                : $request->ip(),
            'country' => $request->header('CF-IPCountry'),
            'region' => $request->header('CF-Region'),
            'city' => $request->header('CF-IPCity'),
            'browser' => $browser,
            'device' => $device,
            'successful' => ! $expired,
            'failure_reason' => $expired ? 'expired' : null,
            'created_at' => now(),
        ]);

        if ($expired) {
            return response()->view('expired', ['link' => $link], 410);
        }

        return redirect()->away($link->destination_url, 302);
    }
}

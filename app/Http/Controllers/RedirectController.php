<?php

namespace App\Http\Controllers;

use App\Models\Link;
use App\Models\PublicDomain;
use App\Support\SafeProxyUrl;
use App\Support\UserAgent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class RedirectController extends Controller
{
    public function __invoke(Request $request, string $slug, ?string $proxyPath = null): RedirectResponse|Response
    {
        $link = Link::where('slug', $slug)->firstOrFail();

        if ($link->expires_at->isPast()) {
            $this->recordVisit($link, $request, false, 'expired');

            return response()->view('expired', ['link' => $link], 410);
        }

        $targetUrl = $this->targetUrl($link->destination_url, $proxyPath, $request->getQueryString());
        if ($link->delivery_mode !== 'proxy') {
            $this->recordVisit($link, $request, true);

            return redirect()->away($targetUrl, 302);
        }

        return $this->proxy($link, $request, $targetUrl);
    }

    private function proxy(Link $link, Request $request, string $targetUrl): Response
    {
        try {
            $blockedHosts = array_filter([
                $request->getHost(),
                parse_url((string) config('app.url'), PHP_URL_HOST),
                ...PublicDomain::pluck('base_url')->map(fn ($url) => parse_url($url, PHP_URL_HOST))->all(),
            ]);
            SafeProxyUrl::assert($targetUrl, $blockedHosts);

            $headers = array_filter([
                'Accept' => $request->header('Accept'),
                'Accept-Language' => $request->header('Accept-Language'),
                'Content-Type' => $request->header('Content-Type'),
                'User-Agent' => $request->userAgent(),
            ]);

            $upstream = Http::timeout(20)
                ->connectTimeout(5)
                ->withHeaders($headers)
                ->withOptions(['allow_redirects' => false])
                ->send($request->method(), $targetUrl, [
                    'body' => $request->getContent(),
                ]);

            $successful = $upstream->status() < 400;
            $this->recordVisit($link, $request, $successful, $successful ? null : 'upstream_'.$upstream->status());

            $body = $request->isMethod('HEAD') ? '' : $upstream->body();
            $contentType = $upstream->header('Content-Type');
            if (str_contains(strtolower($contentType), 'text/html')) {
                $body = $this->rewriteHtml($body, $link);
            } elseif (str_contains(strtolower($contentType), 'text/css')) {
                $body = $this->rewriteCss($body, $link);
            }

            $responseHeaders = array_filter([
                'Content-Type' => $contentType,
                'Cache-Control' => $upstream->header('Cache-Control'),
                'ETag' => $upstream->header('ETag'),
                'Last-Modified' => $upstream->header('Last-Modified'),
            ]);

            if ($upstream->redirect()) {
                $location = $upstream->header('Location');
                if ($location && str_starts_with($location, '/')) {
                    $responseHeaders['Location'] = '/'.$link->slug.$location;
                } elseif ($location) {
                    $responseHeaders['Location'] = $location;
                }
            }

            return response($body, $upstream->status(), $responseHeaders);
        } catch (ConnectionException|RuntimeException $exception) {
            $this->recordVisit($link, $request, false, 'proxy_unavailable');

            return response('The proxied destination is unavailable.', 502, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
    }

    private function targetUrl(string $destination, ?string $path, ?string $query): string
    {
        if ($path) {
            $parts = parse_url($destination);
            $basePath = rtrim((string) ($parts['path'] ?? ''), '/');
            $parts['path'] = $basePath.'/'.ltrim($path, '/');
            $destination = $this->unparseUrl($parts);
        }

        if ($query) {
            $destination .= (str_contains($destination, '?') ? '&' : '?').$query;
        }

        return $destination;
    }

    private function rewriteHtml(string $body, Link $link): string
    {
        $prefix = '/'.$link->slug;
        $body = preg_replace('/\b(src|href|action)=([\'\"])\/(?!\/)/i', '$1=$2'.$prefix.'/', $body) ?? $body;
        $body = $this->rewriteCss($body, $link);

        if (stripos($body, '<head') !== false && stripos($body, '<base ') === false) {
            $body = preg_replace('/(<head[^>]*>)/i', '$1<base href="'.$prefix.'/">', $body, 1) ?? $body;
        }

        return $body;
    }

    private function rewriteCss(string $body, Link $link): string
    {
        return preg_replace('/url\(([\'\"]?)\/(?!\/)/i', 'url($1/'.$link->slug.'/', $body) ?? $body;
    }

    private function recordVisit(Link $link, Request $request, bool $successful, ?string $reason = null): void
    {
        [$browser, $device] = UserAgent::parse($request->userAgent());
        $link->visits()->create([
            'ip_address' => filter_var($request->header('CF-Connecting-IP'), FILTER_VALIDATE_IP)
                ? $request->header('CF-Connecting-IP')
                : $request->ip(),
            'country' => $request->header('CF-IPCountry'),
            'region' => $request->header('CF-Region'),
            'city' => $request->header('CF-IPCity'),
            'browser' => $browser,
            'device' => $device,
            'successful' => $successful,
            'failure_reason' => $reason,
            'created_at' => now(),
        ]);
    }

    private function unparseUrl(array $parts): string
    {
        $authority = ($parts['user'] ?? '').(isset($parts['pass']) ? ':'.$parts['pass'] : '');
        $authority = $authority !== '' ? $authority.'@' : '';
        $authority .= $parts['host'] ?? '';
        $authority .= isset($parts['port']) ? ':'.$parts['port'] : '';

        return ($parts['scheme'] ?? 'https').'://'.$authority.($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}

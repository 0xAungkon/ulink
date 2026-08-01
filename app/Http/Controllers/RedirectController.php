<?php

namespace App\Http\Controllers;

use App\Models\Link;
use App\Models\ProxySession;
use App\Models\PublicDomain;
use App\Support\SafeProxyUrl;
use App\Support\UserAgent;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class RedirectController extends Controller
{
    public function dispatch(Request $request, string $proxyPath): RedirectResponse|Response
    {
        $segments = explode('/', trim($proxyPath, '/'));
        $candidate = (string) array_shift($segments);
        $link = preg_match('/^[a-z0-9]{10}$/', $candidate)
            ? Link::where('slug', $candidate)->first()
            : null;

        if ($link) {
            return $this->handle($request, $link, implode('/', $segments) ?: null);
        }

        $contextSlug = $request->header('X-ULink-Proxy') ?: $this->slugFromReferer($request);
        $contextLink = preg_match('/^[a-z0-9]{10}$/', (string) $contextSlug)
            ? Link::where('slug', $contextSlug)->where('delivery_mode', 'proxy')->first()
            : null;
        abort_unless($contextLink, 404);

        return $this->handle($request, $contextLink, trim($proxyPath, '/'));
    }

    private function handle(Request $request, Link $link, ?string $proxyPath): RedirectResponse|Response
    {
        if ($link->expires_at->isPast()) {
            $this->recordVisit($link, $request, false, 'expired');

            return response()->view('expired', ['link' => $link], 410);
        }

        if ($this->shouldShowCaution($request, $link, $proxyPath)) {
            return $this->caution($request, $link);
        }

        $query = $request->query();
        $continuing = $this->validCautionContinue($request, $link);
        unset($query['__ulink_continue']);
        $targetUrl = $this->targetUrl($link->destination_url, $proxyPath, http_build_query($query));

        if ($link->delivery_mode !== 'proxy') {
            $this->recordVisit($link, $request, true);
            $response = redirect()->away($targetUrl, 302);
        } else {
            $response = $this->proxy($link, $request, $targetUrl);
        }

        if ($continuing) {
            $response->headers->setCookie(cookie(
                $this->consentCookie($link),
                '1',
                60 * 24 * 365,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'Lax',
            ));
            $response->headers->clearCookie($this->cautionNonceCookie($link), '/');
        }

        return $response;
    }

    private function shouldShowCaution(Request $request, Link $link, ?string $proxyPath): bool
    {
        return $proxyPath === null
            && $request->isMethod('GET')
            && str_contains(strtolower((string) $request->header('Accept')), 'text/html')
            && ! $this->validCautionContinue($request, $link)
            && ! $request->cookie($this->consentCookie($link))
            && ! $request->header('X-ULink-No-Screen');
    }

    private function caution(Request $request, Link $link): Response
    {
        $host = (string) parse_url($link->destination_url, PHP_URL_HOST);
        $resolved = $host !== '' ? gethostbyname($host) : null;
        $ip = $resolved && $resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) ? $resolved : null;

        $nonce = Str::random(48);
        $response = response()->view('caution', [
            'link' => $link,
            'host' => $host,
            'ip' => $ip,
            'continueUrl' => $request->fullUrlWithQuery(['__ulink_continue' => $nonce]),
        ]);
        $response->headers->setCookie(cookie(
            $this->cautionNonceCookie($link),
            $nonce,
            10,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'Lax',
        ));

        return $response;
    }

    private function proxy(Link $link, Request $request, string $targetUrl): Response
    {
        $visitorKey = $request->cookie('ulink_proxy_visitor');
        if (! is_string($visitorKey) || ! preg_match('/^[A-Za-z0-9]{40,64}$/', $visitorKey)) {
            $visitorKey = Str::random(48);
        }

        $proxySession = ProxySession::firstOrCreate(
            ['link_id' => $link->id, 'visitor_key' => $visitorKey],
            ['cookies' => []],
        );
        $jar = new CookieJar(false, array_map(
            fn (array $storedCookie) => new SetCookie($storedCookie),
            $proxySession->cookies ?? [],
        ));

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
                'X-Requested-With' => $request->header('X-Requested-With'),
            ]);

            $upstream = Http::timeout(20)
                ->connectTimeout(5)
                ->withHeaders($headers)
                ->withOptions(['allow_redirects' => false, 'cookies' => $jar])
                ->send($request->method(), $targetUrl, ['body' => $request->getContent()]);

            $proxySession->update(['cookies' => $jar->toArray()]);
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
            if ($upstream->redirect() && ($location = $upstream->header('Location'))) {
                $responseHeaders['Location'] = str_starts_with($location, '/') ? '/'.$link->slug.$location : $location;
            }

            $response = response($body, $upstream->status(), $responseHeaders);
            $response->headers->setCookie(cookie(
                'ulink_proxy_visitor',
                $visitorKey,
                60 * 24 * 365,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'Lax',
            ));

            return $response;
        } catch (ConnectionException|RuntimeException $exception) {
            $this->recordVisit($link, $request, false, 'proxy_unavailable');

            return response('The proxied destination is unavailable.', 502, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
    }

    private function targetUrl(string $destination, ?string $path, ?string $query): string
    {
        if ($path) {
            $parts = parse_url($destination);
            $parts['path'] = rtrim((string) ($parts['path'] ?? ''), '/').'/'.ltrim($path, '/');
            $destination = $this->unparseUrl($parts);
        }

        return $query ? $destination.(str_contains($destination, '?') ? '&' : '?').$query : $destination;
    }

    private function rewriteHtml(string $body, Link $link): string
    {
        $prefix = '/'.$link->slug;
        $body = preg_replace('/\b(src|href|action)=([\'\"])\/(?!\/)/i', '$1=$2'.$prefix.'/', $body) ?? $body;
        $body = $this->rewriteCss($body, $link);
        $script = $this->proxyBrowserScript($link);

        if (stripos($body, '<head') !== false) {
            $headContent = '<base href="'.$prefix.'/">'.$script;
            $body = preg_replace('/(<head[^>]*>)/i', '$1'.$headContent, $body, 1) ?? $body;
        } else {
            $body = $script.$body;
        }

        return $body;
    }

    private function proxyBrowserScript(Link $link): string
    {
        $prefix = json_encode('/'.$link->slug, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $slug = json_encode($link->slug, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return <<<HTML
<script data-ulink-proxy>(function(){const p={$prefix},s={$slug};const rw=function(v){try{const u=new URL(String(v),location.href);if(u.origin===location.origin&&u.pathname!==p&&!u.pathname.startsWith(p+'/'))return p+u.pathname+u.search+u.hash}catch(e){}return v};const f=window.fetch;window.fetch=function(i,o){o=o||{};const h=new Headers(o.headers||(i instanceof Request?i.headers:{}));h.set('X-ULink-Proxy',s);o.headers=h;if(i instanceof Request)i=new Request(rw(i.url),i);else i=rw(i);return f.call(this,i,o)};const xo=XMLHttpRequest.prototype.open,xs=XMLHttpRequest.prototype.send;XMLHttpRequest.prototype.open=function(m,u){arguments[1]=rw(u);this.__ulink=true;return xo.apply(this,arguments)};XMLHttpRequest.prototype.send=function(){if(this.__ulink)this.setRequestHeader('X-ULink-Proxy',s);return xs.apply(this,arguments)};document.addEventListener('submit',function(e){const f=e.target;if(f&&f.action)f.action=rw(f.action)},true)})();</script>
HTML;
    }

    private function rewriteCss(string $body, Link $link): string
    {
        return preg_replace('/url\(([\'\"]?)\/(?!\/)/i', 'url($1/'.$link->slug.'/', $body) ?? $body;
    }

    private function recordVisit(Link $link, Request $request, bool $successful, ?string $reason = null): void
    {
        [$browser, $device] = UserAgent::parse($request->userAgent());
        $link->visits()->create([
            'ip_address' => filter_var($request->header('CF-Connecting-IP'), FILTER_VALIDATE_IP) ? $request->header('CF-Connecting-IP') : $request->ip(),
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

    private function slugFromReferer(Request $request): ?string
    {
        $path = parse_url((string) $request->header('Referer'), PHP_URL_PATH);

        return $path ? explode('/', trim($path, '/'))[0] ?? null : null;
    }

    private function consentCookie(Link $link): string
    {
        return 'ulink_trust_'.$link->slug;
    }

    private function cautionNonceCookie(Link $link): string
    {
        return 'ulink_caution_'.$link->slug;
    }

    private function validCautionContinue(Request $request, Link $link): bool
    {
        $provided = $request->query('__ulink_continue');
        $expected = $request->cookie($this->cautionNonceCookie($link));

        return is_string($provided) && is_string($expected) && $provided !== '' && hash_equals($expected, $provided);
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

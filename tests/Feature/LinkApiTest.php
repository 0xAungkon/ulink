<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\ProxySession;
use App\Support\LinkCredentials;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_link_can_be_created_updated_and_inspected(): void
    {
        $created = $this->postJson('/api/links', [
            'url' => 'https://first.trycloudflare.com/path',
            'expire_at' => now()->addMonth()->toIso8601String(),
            'type' => 'anonymous',
        ])->assertCreated()->assertJsonStructure(['token_id', 'secret_key', 'expire_at', 'url'])->json();

        $link = Link::where('token_id', $created['token_id'])->firstOrFail();
        $this->assertNotSame($created['secret_key'], $link->secret_hash);
        $publicUrl = $created['url'];

        $this->putJson('/api/links', [
            'token_id' => $created['token_id'],
            'secret_key' => $created['secret_key'],
            'url' => 'https://second.trycloudflare.com',
        ])->assertOk()->assertJsonPath('url', $publicUrl);

        $this->getJson('/api/links/'.$created['token_id'], [
            'Authorization' => 'Bearer '.$created['token_id'].':'.$created['secret_key'],
        ])->assertOk()
            ->assertJsonPath('destination_url', 'https://second.trycloudflare.com')
            ->assertJsonPath('hits.total', 0);
    }

    public function test_redirects_are_tracked_with_cloudflare_metadata(): void
    {
        $link = Link::create([
            'token_id' => 'test-token',
            'secret_hash' => LinkCredentials::hash('secret'),
            'slug' => 'abcdefghij',
            'destination_url' => 'https://example.com/current',
            'expires_at' => now()->addDay(),
        ]);

        $this->withHeaders([
            'CF-Connecting-IP' => '203.0.113.8',
            'CF-IPCountry' => 'BD',
            'User-Agent' => 'Mozilla/5.0 Chrome/126.0 Mobile',
            'X-ULink-No-Screen' => 'test',
        ])->get('/'.$link->slug)->assertRedirect('https://example.com/current');

        $this->assertDatabaseHas('link_visits', [
            'link_id' => $link->id,
            'ip_address' => '203.0.113.8',
            'country' => 'BD',
            'browser' => 'Chrome',
            'device' => 'Mobile',
            'successful' => true,
        ]);
    }

    public function test_bad_link_credentials_are_rejected(): void
    {
        Link::create([
            'token_id' => 'private-token',
            'secret_hash' => LinkCredentials::hash('correct'),
            'slug' => 'private1234',
            'destination_url' => 'https://example.com',
            'expires_at' => now()->addDay(),
        ]);

        $this->getJson('/api/links/private-token', [
            'Authorization' => 'Bearer private-token:wrong',
        ])->assertUnauthorized();
    }

    public function test_admin_dashboard_uses_environment_credentials(): void
    {
        config(['ulink.admin_username' => 'operator', 'ulink.admin_password' => 'safe-password']);

        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
        $this->withBasicAuth('operator', 'safe-password')
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure(['stats', 'links']);
    }

    public function test_admin_can_view_complete_link_details_without_link_secret(): void
    {
        config(['ulink.admin_username' => 'operator', 'ulink.admin_password' => 'safe-password']);
        $link = Link::create([
            'token_id' => 'admin-visible-token',
            'secret_hash' => LinkCredentials::hash('never-return-this'),
            'slug' => 'detail1234',
            'destination_url' => 'https://example.com/current',
            'delivery_mode' => 'redirect',
            'expires_at' => now()->addDay(),
        ]);
        $link->visits()->create([
            'ip_address' => '203.0.113.10',
            'browser' => 'Chrome',
            'device' => 'Desktop',
            'successful' => true,
            'created_at' => now(),
        ]);

        $this->withBasicAuth('operator', 'safe-password')
            ->getJson('/api/admin/links/'.$link->id)
            ->assertOk()
            ->assertJsonPath('token_id', 'admin-visible-token')
            ->assertJsonPath('delivery_mode', 'redirect')
            ->assertJsonPath('hits.total', 1)
            ->assertJsonPath('users.0.ip', '203.0.113.10')
            ->assertJsonMissing(['secret_hash'])
            ->assertJsonMissing(['secret_key']);
    }

    public function test_admin_can_configure_a_public_domain_and_user_can_select_it(): void
    {
        config(['ulink.admin_username' => 'operator', 'ulink.admin_password' => 'safe-password']);

        $this->getJson('/api/domains')
            ->assertOk()
            ->assertJsonPath('0.base_url', 'http://localhost')
            ->assertJsonPath('0.label', 'Main domain')
            ->assertJsonPath('0.is_default', true);

        $domain = $this->withBasicAuth('operator', 'safe-password')
            ->postJson('/api/admin/domains', [
                'label' => 'Short links',
                'base_url' => 'go.example.com',
            ])->assertCreated()
            ->assertJsonPath('base_url', 'https://go.example.com')
            ->json();

        $this->getJson('/api/domains')
            ->assertOk()
            ->assertJsonPath('0.label', 'Main domain')
            ->assertJsonPath('0.is_default', true);

        $this->withBasicAuth('operator', 'safe-password')
            ->patchJson('/api/admin/domains/'.$domain['id'], ['is_default' => true])
            ->assertOk()
            ->assertJsonPath('is_default', true);

        $this->getJson('/api/domains')
            ->assertOk()
            ->assertJsonPath('0.label', 'Short links')
            ->assertJsonPath('0.is_default', true);

        $created = $this->postJson('/api/links', [
            'url' => 'https://tunnel.trycloudflare.com',
            'expire_at' => now()->addMonth()->toIso8601String(),
            'domain_id' => $domain['id'],
        ])->assertCreated()->json();

        $this->assertStringStartsWith('https://go.example.com/', $created['url']);
    }

    public function test_proxy_mode_fetches_and_rewrites_upstream_content(): void
    {
        Http::fake([
            'http://93.184.216.34/*' => Http::response(
                '<html><head><title>App</title></head><body><img src="/logo.png"></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $link = Link::create([
            'token_id' => 'proxy-token',
            'secret_hash' => LinkCredentials::hash('secret'),
            'slug' => 'proxy12345',
            'destination_url' => 'http://93.184.216.34/app',
            'delivery_mode' => 'proxy',
            'expires_at' => now()->addDay(),
        ]);

        $this->withHeader('X-ULink-No-Screen', 'test')->get('/'.$link->slug)
            ->assertOk()
            ->assertSee('<base href="/proxy12345/">', false)
            ->assertSee('src="/proxy12345/logo.png"', false);

        $this->assertDatabaseHas('link_visits', [
            'link_id' => $link->id,
            'successful' => true,
        ]);
    }

    public function test_proxy_mode_blocks_private_network_targets(): void
    {
        $link = Link::create([
            'token_id' => 'blocked-proxy-token',
            'secret_hash' => LinkCredentials::hash('secret'),
            'slug' => 'block12345',
            'destination_url' => 'http://127.0.0.1/private',
            'delivery_mode' => 'proxy',
            'expires_at' => now()->addDay(),
        ]);

        $this->withHeader('X-ULink-No-Screen', 'test')->get('/'.$link->slug)->assertStatus(502);
        $this->assertDatabaseHas('link_visits', [
            'link_id' => $link->id,
            'successful' => false,
            'failure_reason' => 'proxy_unavailable',
        ]);
    }

    public function test_browser_sees_a_per_link_caution_before_redirecting(): void
    {
        $link = Link::create([
            'token_id' => 'caution-token',
            'secret_hash' => LinkCredentials::hash('secret'),
            'slug' => 'caution123',
            'destination_url' => 'http://93.184.216.34/site',
            'delivery_mode' => 'redirect',
            'expires_at' => now()->addDay(),
        ]);

        $warning = $this->withHeader('Accept', 'text/html')->get('/'.$link->slug)
            ->assertOk()
            ->assertSee('Make sure you trust this website')
            ->assertSee('93.184.216.34');

        $this->assertDatabaseCount('link_visits', 0);

        $nonce = $warning->getCookie('ulink_caution_'.$link->slug)->getValue();
        $this->withCookie('ulink_caution_'.$link->slug, $nonce)
            ->withHeader('Accept', 'text/html')
            ->get('/'.$link->slug.'?__ulink_continue='.$nonce)
            ->assertRedirect('http://93.184.216.34/site')
            ->assertCookie('ulink_trust_'.$link->slug, '1');
    }

    public function test_root_relative_post_is_routed_to_referring_proxy(): void
    {
        Http::fake(['*' => Http::response(['authenticated' => true], 200)]);
        $link = Link::create([
            'token_id' => 'fallback-token',
            'secret_hash' => LinkCredentials::hash('secret'),
            'slug' => 'fallback12',
            'destination_url' => 'http://93.184.216.34/app',
            'delivery_mode' => 'proxy',
            'expires_at' => now()->addDay(),
        ]);

        $this->withHeader('Referer', 'http://localhost/'.$link->slug)
            ->postJson('/login', ['email' => 'person@example.com'])
            ->assertOk()
            ->assertJsonPath('authenticated', true);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'http://93.184.216.34/app/login'
            && str_contains($request->body(), 'person@example.com'));
    }

    public function test_proxy_sessions_are_isolated_for_each_link(): void
    {
        Http::fake(['*' => Http::response('ok', 200, ['Content-Type' => 'text/plain'])]);
        $visitorKey = str_repeat('A', 48);

        foreach (['session001', 'session002'] as $index => $slug) {
            $link = Link::create([
                'token_id' => 'session-token-'.$index,
                'secret_hash' => LinkCredentials::hash('secret'),
                'slug' => $slug,
                'destination_url' => 'http://93.184.216.34/site-'.$index,
                'delivery_mode' => 'proxy',
                'expires_at' => now()->addDay(),
            ]);
            $this->withCookie('ulink_proxy_visitor', $visitorKey)
                ->withHeader('X-ULink-No-Screen', 'test')
                ->get('/'.$link->slug)
                ->assertOk();
        }

        $this->assertDatabaseCount('proxy_sessions', 2);
        $this->assertDatabaseCount('links', 2);
    }

    public function test_upstream_cookies_are_saved_in_the_link_scoped_proxy_session(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('signed in', 200, [
                'Content-Type' => 'text/plain',
                'Set-Cookie' => 'upstream_session=abc123; Path=/; HttpOnly',
            ])
            ->push('profile', 200, ['Content-Type' => 'text/plain'])]);
        $link = Link::create([
            'token_id' => 'cookie-session-token',
            'secret_hash' => LinkCredentials::hash('secret'),
            'slug' => 'cookies123',
            'destination_url' => 'http://93.184.216.34',
            'delivery_mode' => 'proxy',
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->withCookie('ulink_proxy_visitor', str_repeat('B', 48))
            ->withHeader('X-ULink-No-Screen', 'test')
            ->get('/'.$link->slug)
            ->assertOk();

        $session = ProxySession::firstOrFail();
        $this->assertSame('upstream_session', $session->cookies[0]['Name']);
        $this->assertSame('abc123', $session->cookies[0]['Value']);

        $browserName = 'ulink_up_'.$link->slug.'_'.rtrim(strtr(base64_encode('upstream_session'), '+/', '-_'), '=');
        $response->assertPlainCookie($browserName, 'abc123');
        $this->assertSame('/'.$link->slug, $response->getCookie($browserName, false)->getPath());

        EncryptCookies::flushState();
        $this->withCookie('ulink_proxy_visitor', str_repeat('B', 48))
            ->withUnencryptedCookie($browserName, 'changed456')
            ->withHeader('X-ULink-No-Screen', 'test')
            ->get('/'.$link->slug.'/profile')
            ->assertOk();

        $sent = Http::recorded();
        $this->assertStringContainsString('upstream_session=changed456', $sent[1][0]->header('Cookie')[0]);
    }
}

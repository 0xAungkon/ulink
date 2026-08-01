<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Support\LinkCredentials;
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
            ->assertJsonPath('0.id', null);

        $domain = $this->withBasicAuth('operator', 'safe-password')
            ->postJson('/api/admin/domains', [
                'label' => 'Short links',
                'base_url' => 'go.example.com',
            ])->assertCreated()
            ->assertJsonPath('base_url', 'https://go.example.com')
            ->json();

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

        $this->get('/'.$link->slug)
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

        $this->get('/'.$link->slug)->assertStatus(502);
        $this->assertDatabaseHas('link_visits', [
            'link_id' => $link->id,
            'successful' => false,
            'failure_reason' => 'proxy_unavailable',
        ]);
    }
}

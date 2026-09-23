<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_sent(): void
    {
        $response = $this->get(route('login'));

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("script-src 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_private_storage_is_not_served_directly(): void
    {
        $this->get('/storage/branding/logo.png')->assertNotFound();
    }

    public function test_every_settings_page_requires_login(): void
    {
        foreach (['company', 'branding', 'vat', 'numbering', 'account'] as $page) {
            $this->get(route("settings.$page"))->assertRedirect(route('login'));
        }
    }

    public function test_unknown_module_returns_404(): void
    {
        $this->actingAs($this->admin())->get('/inexistant')->assertNotFound();
    }

    public function test_app_pages_are_not_indexed_by_search_engines(): void
    {
        $this->get(route('login'))->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }
}

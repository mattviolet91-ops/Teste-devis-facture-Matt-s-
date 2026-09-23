<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Services\PushService;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class PushTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_page_offers_notifications_with_generated_vapid_keys(): void
    {
        $this->actingAs($this->admin())->get(route('settings.account'))->assertOk()
            ->assertSee('Notifications sur le téléphone')->assertSee('data-push-key', false);

        $settings = app(Settings::class);
        $this->assertSame(87, strlen($settings->get('push.public_key')));
        $this->assertSame(43, strlen(Crypt::decryptString($settings->get('push.private_key'))), 'Clé privée chiffrée.');
    }

    public function test_device_can_subscribe_and_unsubscribe(): void
    {
        $this->actingAs($this->admin());
        $payload = [
            'endpoint' => 'https://web.push.apple.com/abc123',
            'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'],
        ];

        $this->postJson(route('push.subscribe'), $payload)->assertOk();
        $this->postJson(route('push.subscribe'), $payload)->assertOk();
        $this->assertSame(1, PushSubscription::query()->count(), 'Pas de doublon.');

        $this->postJson(route('push.unsubscribe'), ['endpoint' => $payload['endpoint']])->assertOk();
        $this->assertSame(0, PushSubscription::query()->count());

        $this->postJson(route('push.subscribe'), ['endpoint' => 'http://insecure.example', 'keys' => $payload['keys']])->assertUnprocessable();
    }

    public function test_sending_without_devices_does_nothing(): void
    {
        $this->assertSame(0, app(PushService::class)->send('Titre', 'Message'));
    }

    public function test_push_routes_require_login(): void
    {
        $this->postJson(route('push.subscribe'), [])->assertUnauthorized();
    }
}

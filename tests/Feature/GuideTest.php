<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Settings;
use App\Support\GettingStarted;
use App\Support\Guide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_guide_explains_every_page_the_account_can_open(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('dashboard'))->assertSee(route('guide'));
        $this->get(route('guide'))->assertOk()
            ->assertSee('Faire un devis')->assertSee('Devis express (en une phrase)')->assertSee('Factures')
            ->assertSee('Réglages')->assertSee('href="'.route('settings.company').'"', false);
    }

    public function test_a_commercial_account_sees_only_the_parts_it_can_use(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->get(route('guide'))->assertOk()
            ->assertSee('Faire un devis')->assertSee('Planning et rendez-vous')
            ->assertDontSee('Relances de factures')->assertDontSee('Ouvrir les paiements')->assertDontSee('Ouvrir les réglages');
    }

    public function test_help_button_opens_the_section_of_the_current_page(): void
    {
        $this->actingAs($this->admin());
        $this->get(route('invoices.index'))->assertSee('href="'.route('guide').'#factures"', false);
        $this->get(route('quotes.express'))->assertSee('href="'.route('guide').'#devis-express"', false);
        $this->get(route('settings.api'))->assertSee('href="'.route('guide').'#claude"', false);
        $this->get(route('clients.index'))->assertSee('href="'.route('guide').'#clients"', false);
        $this->assertSame(route('guide'), Guide::urlFor('page.inconnue'));
    }

    public function test_getting_started_checks_itself(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->get(route('guide'))->assertSee('Bien démarrer')->assertSee('Bibliothèque de prestations');
        $this->get(route('dashboard'))->assertSee('Bien démarrer :');

        $before = GettingStarted::progress($admin)['done'];
        app(Settings::class)->set(['backups.last_download_at' => now()->toIso8601String()]);
        $this->assertSame($before + 1, GettingStarted::progress($admin)['done']);

        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->get(route('guide'))->assertDontSee('id="bien-demarrer"', false);
        $this->get(route('dashboard'))->assertDontSee('Bien démarrer :');
    }

    public function test_tip_of_the_day_changes_every_day(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $tip = Guide::tipOfTheDay($admin);
        $this->get(route('dashboard'))->assertSee('Astuce du jour')->assertSee(e($tip['text']), false);
        $this->assertNotSame($tip, Guide::tipOfTheDay($admin, now()->addDay()));
    }

    public function test_guide_and_its_screenshots_are_kept_for_offline_use(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $urls = $this->getJson(route('offline.pages'))->assertOk()->json('urls');
        $this->assertContains(route('guide'), $urls);
        foreach (Guide::images($admin) as $image) {
            $this->assertContains($image, $urls);
            $this->assertFileExists(public_path(parse_url($image, PHP_URL_PATH)));
        }
        $this->get(route('guide'))->assertSee('images/guide/facture.jpg');
    }
}

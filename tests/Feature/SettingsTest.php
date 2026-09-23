<?php

namespace Tests\Feature;

use App\Models\NumberSequence;
use App\Models\Unit;
use App\Models\VatRate;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function companyPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'company' => [
                'trade_name' => "Matt's Couverture",
                'owner_name' => 'Matt Violet',
                'legal_form' => 'EI',
                'slogan' => 'Votre couvreur de confiance',
                'address' => '8 chemin de la Plesse',
                'postal_code' => '91140',
                'city' => 'Villebon-sur-Yvette',
                'phone' => '07 67 92 68 36',
                'email' => 'mv.entreprise91@gmail.com',
                'website' => 'https://matts-couverture.fr',
                'siret' => '981 708 167 00011',
            ],
            'bank' => ['iban' => 'fr76 3000 6000 0112 3456 7890 189', 'bic' => 'agrifrpp'],
        ], $overrides);
    }

    public function test_defaults_come_from_company_information(): void
    {
        $settings = app(Settings::class);

        $this->assertSame("Matt's Couverture", $settings->get('company.trade_name'));
        $this->assertSame('98170816700011', $settings->get('company.siret'));
        $this->assertSame('#3CBDE8', $settings->get('branding.color_accent'));
    }

    public function test_company_information_can_be_updated(): void
    {
        $this->actingAs($this->admin())
            ->put(route('settings.company'), $this->companyPayload(['company' => ['phone' => '06 00 00 00 00']]))
            ->assertSessionHasNoErrors();

        $settings = app(Settings::class);
        $this->assertSame('06 00 00 00 00', $settings->get('company.phone'));
        $this->assertSame('98170816700011', $settings->get('company.siret'), 'Les espaces du SIRET sont retirés.');
        $this->assertSame('FR7630006000011234567890189', $settings->get('bank.iban'));
        $this->assertSame('AGRIFRPP', $settings->get('bank.bic'));
        $this->assertDatabaseHas('activity_log', ['action' => 'settings.company']);
    }

    public function test_invalid_siret_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->put(route('settings.company'), $this->companyPayload(['company' => ['siret' => '12345']]))
            ->assertSessionHasErrors(['company.siret' => 'Le SIRET doit comporter 14 chiffres.']);
    }

    public function test_branding_colors_and_fonts_can_be_changed(): void
    {
        $this->actingAs($this->admin())->post(route('settings.branding'), [
            'color_accent' => '#ff6600',
            'color_primary' => '#222222',
            'color_text' => '#111111',
            'color_background' => '#FAFAFA',
            'font_heading' => 'Figtree',
            'font_body' => 'Système',
        ])->assertSessionHasNoErrors();

        $this->assertSame('#FF6600', app(Settings::class)->get('branding.color_accent'));
        $this->get(route('dashboard'))->assertSee('--brand-accent: #FF6600', false);
    }

    public function test_invalid_color_is_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('settings.branding'), [
            'color_accent' => 'red;}</style><script>alert(1)</script>',
            'color_primary' => '#222222',
            'color_text' => '#111111',
            'color_background' => '#FAFAFA',
            'font_heading' => 'Figtree',
            'font_body' => 'Figtree',
        ])->assertSessionHasErrors('color_accent');
    }

    public function test_logo_can_be_uploaded_and_served(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin())->post(route('settings.branding'), $this->brandingPayload([
            'logo' => UploadedFile::fake()->image('logo.png', 400, 120),
        ]))->assertSessionHasNoErrors();

        $path = app(Settings::class)->get('branding.logo_path');
        Storage::disk('local')->assertExists($path);
        $this->get(route('branding.image', 'logo'))->assertOk();
    }

    public function test_icon_can_be_uploaded_separately_and_is_used_in_the_top_bar(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin())->post(route('settings.branding'), $this->brandingPayload([
            'icon' => UploadedFile::fake()->image('icone.png', 256, 256),
        ]))->assertSessionHasNoErrors();

        $path = app(Settings::class)->get('branding.icon_path');
        Storage::disk('local')->assertExists($path);
        $this->assertNull(app(Settings::class)->get('branding.logo_path'), 'Le logo complet n\'est pas touché.');
        $this->get(route('branding.image', 'icone'))->assertOk();
        $this->get(route('dashboard'))->assertSee(route('branding.image', 'icone'), false);
    }

    public function test_bundled_logo_and_icon_are_used_by_default(): void
    {
        $this->get(route('login'))->assertSee('images/logo.png', false);
        $this->actingAs($this->admin())->get(route('dashboard'))->assertSee('images/marque.png', false);
        $this->assertFileExists(public_path('images/logo.png'));
        $this->assertFileExists(public_path('images/marque.png'));
    }

    public function test_svg_logo_is_refused(): void
    {
        Storage::fake('local');

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs($this->admin())
            ->post(route('settings.branding'), $this->brandingPayload(['logo' => $svg]))
            ->assertSessionHasErrors('logo');
    }

    public function test_logo_can_be_removed(): void
    {
        Storage::fake('local');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('settings.branding'), $this->brandingPayload([
            'logo' => UploadedFile::fake()->image('logo.png'),
        ]));
        $path = app(Settings::class)->get('branding.logo_path');

        $this->actingAs($admin)->post(route('settings.branding'), $this->brandingPayload(['remove_logo' => '1']));

        Storage::disk('local')->assertMissing($path);
        $this->assertNull(app(Settings::class)->get('branding.logo_path'));
        $this->get(route('branding.image', 'logo'))->assertNotFound();
    }

    public function test_branding_can_be_reset_to_site_colors(): void
    {
        app(Settings::class)->set(['branding.color_accent' => '#000000']);

        $this->actingAs($this->admin())->post(route('settings.branding.reset'));

        $this->assertSame('#3CBDE8', app(Settings::class)->get('branding.color_accent'));
    }

    public function test_vat_regime_can_be_switched_to_franchise(): void
    {
        $this->actingAs($this->admin())->put(route('settings.vat.regime'), [
            'regime' => 'franchise',
            'franchise_mention' => 'TVA non applicable, art. 293 B du CGI',
        ])->assertSessionHasNoErrors();

        $settings = app(Settings::class);
        $this->assertSame('franchise', $settings->get('vat.regime'));
        $this->assertFalse($settings->get('vat.reduced_rate_mention_enabled'));
    }

    public function test_default_vat_rates_are_installed(): void
    {
        $this->assertSame([1000, 2000, 550, 0], VatRate::query()->ordered()->pluck('rate')->all());
        $this->assertSame(1000, VatRate::query()->where('is_default', true)->value('rate'));
    }

    public function test_vat_rate_accepts_french_decimal_and_becomes_single_default(): void
    {
        $this->actingAs($this->admin())->post(route('settings.vat.rates.store'), [
            'label' => 'TVA 8,5 %',
            'rate' => '8,5',
            'is_default' => '1',
        ])->assertSessionHasNoErrors();

        $rate = VatRate::query()->where('label', 'TVA 8,5 %')->firstOrFail();
        $this->assertSame(850, $rate->rate);
        $this->assertSame('8,5 %', $rate->percentLabel());
        $this->assertSame(1, VatRate::query()->where('is_default', true)->count());
        $this->assertTrue($rate->fresh()->is_default);
    }

    public function test_vat_rate_can_be_deactivated(): void
    {
        $rate = VatRate::query()->where('rate', 550)->firstOrFail();

        $this->actingAs($this->admin())->put(route('settings.vat.rates.update', $rate), [
            'label' => $rate->label,
            'rate' => '5.5',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($rate->fresh()->is_active);
    }

    public function test_units_can_be_added_but_not_duplicated(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('settings.units.store'), ['code' => 'm³', 'label' => 'Mètre cube'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('settings.units.store'), ['code' => 'm²', 'label' => 'Doublon'])
            ->assertSessionHasErrors('code');

        $this->assertSame(['m²', 'ml', 'u', 'forfait', 'h', 'm³'], Unit::query()->ordered()->pluck('code')->all());
    }

    public function test_numbering_can_be_configured(): void
    {
        $this->actingAs($this->admin())->put(route('settings.numbering'), [
            'quote_validity_days' => 45,
            'sequences' => [
                'quote' => ['prefix' => 'DEV', 'next_number' => 16],
                'invoice' => ['prefix' => 'FAC', 'next_number' => 1],
                'credit_note' => ['prefix' => 'AV', 'next_number' => 1],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(16, NumberSequence::query()->where('type', 'quote')->value('next_number'));
        $this->assertSame(45, app(Settings::class)->get('documents.quote_validity_days'));
    }

    public function test_numbering_prefix_must_be_uppercase_letters_or_digits(): void
    {
        $this->actingAs($this->admin())->put(route('settings.numbering'), [
            'quote_validity_days' => 30,
            'sequences' => [
                'quote' => ['prefix' => 'dev-', 'next_number' => 1],
                'invoice' => ['prefix' => 'FAC', 'next_number' => 1],
                'credit_note' => ['prefix' => 'AV', 'next_number' => 1],
            ],
        ])->assertSessionHasErrors('sequences.quote.prefix');
    }

    public function test_password_change_requires_current_password(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('settings.account.password'), [
            'current_password' => 'faux',
            'password' => 'nouveau-mot-de-passe-42',
            'password_confirmation' => 'nouveau-mot-de-passe-42',
        ])->assertSessionHasErrors('current_password');

        $this->actingAs($admin)->put(route('settings.account.password'), [
            'current_password' => 'password',
            'password' => 'nouveau-mot-de-passe-42',
            'password_confirmation' => 'nouveau-mot-de-passe-42',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('nouveau-mot-de-passe-42', $admin->fresh()->password));
    }

    public function test_all_settings_pages_render(): void
    {
        $admin = $this->admin();

        foreach (['company', 'branding', 'vat', 'numbering', 'account'] as $page) {
            $this->actingAs($admin)->get(route("settings.$page"))->assertOk();
        }
    }

    private function brandingPayload(array $extra = []): array
    {
        return [
            'color_accent' => '#3CBDE8',
            'color_primary' => '#494949',
            'color_text' => '#2C3E50',
            'color_background' => '#ECF0F1',
            'font_heading' => 'Montserrat',
            'font_body' => 'Figtree',
        ] + $extra;
    }
}

<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\QuoteRequest;
use App\Services\MailSettings;
use App\Services\PushService;
use App\Services\QuoteRequestService;
use App\Services\Settings;
use App\Services\SiteFormImporter;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class SiteFormImportTest extends TestCase
{
    use RefreshDatabase;

    private const JETPACK_TEXT = <<<'TXT'
Nom: Julie Garnier
E-mail: julie.garnier@example.com
Téléphone: 06 45 67 89 01
Adresse: 12 rue des Tilleuls, 91140 Villebon-sur-Yvette
Type de travaux: Démoussage + gouttières
Message: Bonjour,
beaucoup de mousse sur le pan nord.
Merci

Time: 6 octobre 2026 à 9 h 12 min
IP Address: 203.0.113.9
Contact Form URL: https://matts-couverture.fr/contact/
Sent by an unverified visitor to your site.
TXT;

    private const JETPACK_HTML = '<table><tr><td><strong>Votre nom</strong></td></tr><tr><td>Pierre Martin</td></tr>'
        .'<tr><td><strong>Téléphone</strong></td></tr><tr><td>07&nbsp;11&nbsp;22&nbsp;33&nbsp;44</td></tr>'
        .'<tr><td><strong>Ville</strong></td></tr><tr><td>Massy</td></tr>'
        .'<tr><td><strong>Votre message</strong></td></tr><tr><td>Fuite au niveau du Velux<br>Urgent</td></tr></table>';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 10:00');
        $this->app->instance(PushService::class, Mockery::mock(PushService::class)->shouldIgnoreMissing());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function message(string $text, array $overrides = []): array
    {
        return $overrides + ['id' => '<'.md5($text).'@matts-couverture.fr>', 'from' => 'wordpress@matts-couverture.fr', 'reply_to' => '',
            'subject' => "[Matt's Couverture] Demande de devis", 'date' => now(), 'text' => $text];
    }

    public function test_plain_and_html_form_emails_are_understood(): void
    {
        $importer = app(SiteFormImporter::class);

        $data = $importer->parse(self::JETPACK_TEXT);
        $this->assertSame(['Julie', 'Garnier', '06 45 67 89 01', 'julie.garnier@example.com', '12 rue des Tilleuls', '91140', 'Villebon-sur-Yvette'],
            [$data['first_name'], $data['last_name'], $data['phone'], $data['email'], $data['address'], $data['postal_code'], $data['city']]);
        $this->assertSame(['demoussage', 'zinguerie'], $data['works']);
        $this->assertStringContainsString("beaucoup de mousse sur le pan nord.\nMerci", $data['message']);
        $this->assertStringNotContainsString('203.0.113.9', $data['message']);

        $data = $importer->parse(self::JETPACK_HTML);
        $this->assertSame(['Pierre', 'Martin', 'Massy'], [$data['first_name'], $data['last_name'], $data['city']]);
        $this->assertSame('07 11 22 33 44', Phone::format($data['phone']));
        $this->assertSame(['fuite', 'velux'], $data['works']);
        $this->assertStringContainsString('Urgent', $data['message']);
    }

    public function test_only_form_emails_are_imported_once(): void
    {
        app(Settings::class)->set(['site_form.enabled' => true, 'site_form.from' => 'wordpress@']);
        $importer = app(SiteFormImporter::class);
        $messages = [
            $this->message(self::JETPACK_TEXT),
            $this->message(self::JETPACK_HTML, ['reply_to' => 'pierre@example.com']),
            $this->message('Votre facture EDF', ['from' => 'facture@edf.fr', 'subject' => 'Votre facture']),
        ];

        $this->assertCount(2, $importer->import($messages));
        $this->assertCount(0, $importer->import($messages), 'Déjà lus : ignorés.');

        $this->assertSame(2, QuoteRequest::query()->count());
        $pierre = Client::query()->where('last_name', 'Martin')->sole();
        $this->assertSame(['pierre@example.com', 'site', 'Formulaire du site internet'], [$pierre->email, $pierre->source, $pierre->source_detail]);
        $this->assertSame('Villebon-sur-Yvette', Client::query()->where('last_name', 'Garnier')->sole()->worksites()->sole()->city);
    }

    public function test_settings_page_preview_and_command(): void
    {
        $this->actingAs($this->admin());
        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'password' => 'abcdabcdabcdabcd']);
        $this->get(route('settings.site-form'))->assertOk()->assertSee('Formulaire de votre site internet');
        $this->put(route('settings.site-form'), ['enabled' => '1'])->assertSessionHasNoErrors();

        $fake = Mockery::mock(SiteFormImporter::class, [app(Settings::class), app(MailSettings::class), app(QuoteRequestService::class)])->makePartial();
        $fake->shouldReceive('fetch')->andReturn([$this->message(self::JETPACK_TEXT), $this->message('Bonjour', ['subject' => 'Autre chose', 'id' => 'x', 'from' => 'ami@example.com'])]);
        $this->app->instance(SiteFormImporter::class, $fake);

        $this->followingRedirects()->post(route('settings.site-form.preview'))->assertOk()
            ->assertSee('formulaire')->assertSee('Julie · Garnier · 06 45 67 89 01', false)->assertSee('Autre chose')
            ->assertSee('dont <strong>1 reconnu(s) comme venant du formulaire', false);
        $this->assertSame(0, QuoteRequest::query()->count(), 'L\'aperçu ne crée rien.');

        $this->artisan('app:import-site-requests')->expectsOutputToContain('1 demande(s) importée(s)');
        $this->get(route('settings.site-form'))->assertSee('1 demande(s) importée(s)');
    }

    public function test_automatic_detection_without_any_setting(): void
    {
        app(Settings::class)->set(['site_form.enabled' => true, 'site_form.from' => '', 'site_form.subject' => '']);
        $importer = app(SiteFormImporter::class);

        // Jetpack envoie souvent au nom du visiteur : l'expéditeur change à chaque demande.
        $this->assertTrue($importer->matches($this->message(self::JETPACK_TEXT, ['from' => 'julie.garnier@example.com', 'subject' => 'Demande de devis'])));
        $this->assertTrue($importer->matches($this->message('Nom: X', ['from' => 'donotreply@wordpress.com'])));
        $this->assertFalse($importer->matches($this->message('Votre facture est disponible', ['from' => 'facture@edf.fr', 'subject' => 'Facture'])));
        $this->assertFalse($importer->matches($this->message(self::JETPACK_TEXT, ['subject' => 'Re: Demande de devis'])), 'Une réponse n\'est pas une nouvelle demande.');
    }

    public function test_elementor_email_from_the_real_site_form(): void
    {
        // Email envoyé par le formulaire Elementor de matts-couverture.fr (libellés réels).
        $html = 'Nom et prénom: Marie Dupont<br>Numéro de téléphone: 06 78 90 12 34<br>Email: mariedupont@example.fr<br>'
            .'Type de travaux: Rénovation de toiture<br>Votre commune: Palaiseau<br>Décrivez-nous votre demande: Tuiles cassées après la tempête, possible fuite<br>'
            .'<br>---<br>Date: 25 septembre 2026<br>Heure: 10 h 12<br>URL de la page: https://matts-couverture.fr/contact-2/<br>'
            .'Agent utilisateur: Mozilla/5.0<br>IP distante: 203.0.113.4<br>Propulsé par: Elementor<br>';

        app(Settings::class)->set(['site_form.enabled' => true, 'site_form.from' => '', 'site_form.subject' => '']);
        $importer = app(SiteFormImporter::class);
        $message = $this->message($html, ['from' => 'wordpress@matts-couverture.fr', 'subject' => 'Nouveau message de « Matt\'s Couverture »']);
        $this->assertTrue($importer->matches($message));

        $data = $importer->parse($html);
        $this->assertSame(['Marie', 'Dupont', '06 78 90 12 34', 'mariedupont@example.fr', 'Palaiseau'],
            [$data['first_name'], $data['last_name'], $data['phone'], $data['email'], $data['city']]);
        $this->assertEqualsCanonicalizing(['couverture', 'fuite'], $data['works']);
        $this->assertStringContainsString('Tuiles cassées après la tempête', $data['message']);
        $this->assertStringNotContainsString('203.0.113.4', $data['message']);
        $this->assertStringNotContainsString('Mozilla', $data['message']);

        $this->assertCount(1, $importer->import([$message]));
        $this->assertSame('Palaiseau', Client::query()->where('last_name', 'Dupont')->sole()->city);
    }
}

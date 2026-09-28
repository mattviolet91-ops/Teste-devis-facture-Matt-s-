<?php

namespace Tests\Feature;

use App\Mail\ClientMessage;
use App\Models\Client;
use App\Models\Intervention;
use App\Models\Photo;
use App\Models\Report;
use App\Models\SentEmail;
use App\Models\User;
use App\Models\Worksite;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Intervention $intervention;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->admin());
        $this->client = Client::factory()->create(['civility' => 'Mme', 'last_name' => 'Durand', 'email' => 'durand@example.com', 'phone' => '06 12 34 56 78']);
        $worksite = Worksite::factory()->for($this->client)->create(['address' => '4 rue des Roses', 'city' => 'Massy']);
        $this->intervention = Intervention::query()->create(['kind' => 'chantier', 'client_id' => $this->client->id, 'worksite_id' => $worksite->id,
            'title' => 'Recherche de fuite', 'starts_on' => today()->toDateString(), 'ends_on' => today()->toDateString(), 'status' => 'done']);
    }

    private function createReport(): Report
    {
        $this->get(route('reports.create', ['intervention' => $this->intervention->id]))->assertOk()->assertSee('Recherche de fuite');

        $this->post(route('reports.store'), [
            'client_id' => $this->client->id, 'intervention_id' => $this->intervention->id, 'worksite_id' => $this->intervention->worksite_id,
            'title' => 'Recherche de fuite', 'visit_date' => today()->toDateString(),
            'findings' => "Solin de cheminée fissuré.\n\nTrois tuiles cassées côté nord.",
            'work_done' => 'Bâchage et remplacement des tuiles.',
            'recommendations' => 'Réfection du solin en zinc à prévoir.',
        ])->assertSessionHasNoErrors();

        return Report::query()->sole();
    }

    public function test_report_with_photos_is_created_from_the_intervention(): void
    {
        $this->get(route('planning.show', $this->intervention))->assertSee('Faire le rapport d\'intervention', false);
        $report = $this->createReport();
        $this->assertSame([$this->intervention->id, $this->client->id], [$report->intervention_id, $report->client_id]);

        // Photos prises depuis le rapport : rangées sur le chantier du client et imprimées dans le PDF.
        $this->post(route('reports.photos.upload', $report), ['photos' => [UploadedFile::fake()->image('fuite.jpg', 800, 600)], 'category' => 'probleme'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $report->photos()->count());
        $this->assertSame($this->intervention->worksite_id, $report->photos()->first()->worksite_id);

        $this->get(route('reports.show', $report))->assertOk()->assertSee('Solin de cheminée fissuré.')->assertSee('Photos dans le PDF')
            ->assertSee('/r/', false);
        $pdf = $this->get(route('reports.pdf', $report))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $html = view('pdf.report', ['report' => $report->fresh(), 'company' => app(Settings::class)->group('company'),
            'insurance' => app(Settings::class)->group('insurance'), 'colors' => [], 'logo' => null])->render();
        $this->assertStringContainsString('Préconisations', $html);
        $this->assertStringContainsString('QBE', $html);
        $this->assertStringNotContainsString('Violet', $html);

        // Le rapport est ensuite accessible depuis le rendez-vous et la fiche client.
        $this->get(route('planning.show', $this->intervention))->assertSee(route('reports.show', $report));
        $this->get(route('clients.show', $this->client))->assertSee('Rapports d\'intervention', false)->assertSee('Pas encore envoyé');
    }

    public function test_client_opens_the_report_with_the_link_without_login(): void
    {
        $report = $this->createReport();
        $url = $report->publicUrl();
        $token = basename($url);

        auth()->logout();
        $response = $this->get(route('portal.report', $token))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->get(route('portal.report', str_repeat('a', 48)))->assertNotFound();
        $this->get(route('reports.show', $report))->assertRedirect(route('login'));
    }

    public function test_report_is_emailed_with_the_pdf(): void
    {
        Mail::fake();
        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'password' => 'abcdabcdabcdabcd']);
        $report = $this->createReport();

        $this->post(route('reports.email', $report), ['to' => 'durand@example.com', 'message' => "Bonjour Madame Durand,\nVoici le rapport :\n{lien}"])
            ->assertSessionHasNoErrors();

        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => $m->hasTo('durand@example.com')
            && str_starts_with((string) $m->pdf, '%PDF') && str_contains((string) $m->pdfName, 'Rapport')
            && str_contains($m->text, '/r/') && $m->buttonLabel === 'Voir le rapport');
        $this->assertNotNull($report->fresh()->sent_at);
        $this->assertSame(1, SentEmail::query()->count());
    }

    public function test_commercial_can_write_reports_and_photos_of_another_client_are_refused(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $report = $this->createReport();

        $other = Client::factory()->create();
        $foreign = Photo::query()->forceCreate(['client_id' => $other->id, 'category' => 'avant', 'path' => 'x.jpg', 'thumb_path' => 'x-mini.jpg']);
        $this->post(route('reports.photos', $report), ['photos' => [$foreign->id]]);
        $this->assertSame(0, $report->photos()->count());

        $this->delete(route('reports.destroy', $report))->assertRedirect(route('clients.show', $this->client));
        $this->assertSame(0, Report::query()->count());
    }

    public function test_report_for_a_client_without_worksite(): void
    {
        $client = Client::factory()->create(['last_name' => 'Sansadresse']);
        $this->post(route('reports.store'), ['client_id' => $client->id, 'title' => 'Urgence', 'visit_date' => today()->toDateString(), 'findings' => 'Fuite.'])
            ->assertSessionHasNoErrors();
        $this->assertNull(Report::query()->where('client_id', $client->id)->sole()->worksite_id);
    }
}

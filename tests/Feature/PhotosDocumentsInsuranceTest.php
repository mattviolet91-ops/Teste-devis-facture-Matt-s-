<?php

namespace Tests\Feature;

use App\Mail\ClientMessage;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\InsuranceCertificate;
use App\Models\Photo;
use App\Models\Quote;
use App\Models\Worksite;
use App\Services\PdfService;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotosDocumentsInsuranceTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private Worksite $worksite;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-29 10:00');
        $this->client = Client::factory()->create(['email' => 'client@example.com']);
        $this->worksite = Worksite::factory()->for($this->client)->create();
        $this->actingAs($this->admin());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function upload(int $count = 1, string $category = 'avant'): void
    {
        $files = array_map(fn ($i) => UploadedFile::fake()->image("toit-$i.jpg", 3000, 2000), range(1, $count));
        $this->post(route('photos.store', $this->worksite), ['photos' => $files, 'category' => $category, 'caption' => 'Faîtage'])
            ->assertSessionHasNoErrors();
    }

    public function test_photos_are_resized_with_thumbnail_and_category(): void
    {
        $this->upload(2, 'probleme');

        $photos = Photo::query()->get();
        $this->assertCount(2, $photos);
        $photo = $photos->first();
        $this->assertSame('probleme', $photo->category);
        $this->assertSame('Faîtage', $photo->caption);
        $this->assertSame(2000, $photo->width);
        $this->assertSame(1333, $photo->height);
        Storage::disk('local')->assertExists([$photo->path, $photo->thumb_path]);
        $this->assertSame(480, getimagesizefromstring(Storage::disk('local')->get($photo->thumb_path))[0]);

        $this->get(route('photos.worksite', $this->worksite))->assertOk()->assertSee('Problème')->assertSee('Faîtage');
        $this->get(route('photos.index', ['categorie' => 'probleme']))->assertOk()->assertSee(route('photos.file', [$photo, 'mini']));
        $this->get(route('clients.show', $this->client))->assertSee('Photos (2)');
    }

    public function test_upload_answers_json_for_the_offline_queue(): void
    {
        $this->postJson(route('photos.store', $this->worksite), [
            'photos' => [UploadedFile::fake()->image('a.jpg', 800, 600)], 'category' => 'apres',
        ])->assertCreated()->assertJson(['count' => 1]);
    }

    public function test_non_image_files_are_refused(): void
    {
        $this->post(route('photos.store', $this->worksite), [
            'photos' => [UploadedFile::fake()->create('virus.exe', 10)], 'category' => 'avant',
        ])->assertSessionHasErrors('photos.0');
        $this->assertSame(0, Photo::query()->count());
    }

    public function test_photo_files_are_private(): void
    {
        $this->upload();
        $photo = Photo::query()->first();

        $this->get(route('photos.file', [$photo, 'mini']))->assertOk();
        auth()->logout();
        $this->get(route('photos.file', [$photo, 'mini']))->assertRedirect(route('login'));
    }

    public function test_photo_can_be_edited_annotated_and_deleted(): void
    {
        $this->upload();
        $photo = Photo::query()->first();

        $this->put(route('photos.update', $photo), ['category' => 'apres', 'caption' => 'Après nettoyage'])->assertSessionHasNoErrors();
        $this->assertSame('apres', $photo->fresh()->category);

        $this->post(route('photos.annotate', $photo), ['image' => UploadedFile::fake()->image('annotation.jpg', 2000, 1333)])->assertOk();
        $photo->refresh();
        $this->assertNotNull($photo->annotated_path);
        Storage::disk('local')->assertExists([$photo->annotated_path, $photo->path]);

        $this->post(route('photos.annotate', $photo), ['remove' => '1']);
        $this->assertNull($photo->fresh()->annotated_path);

        $paths = [$photo->fresh()->path, $photo->fresh()->thumb_path];
        $this->delete(route('photos.destroy', $photo));
        $this->assertModelMissing($photo);
        Storage::disk('local')->assertMissing($paths);
    }

    public function test_photos_are_printed_in_the_pdf_annex_of_a_draft(): void
    {
        $this->upload(2);
        $this->post(route('quotes.store'), [
            'client_id' => $this->client->id, 'worksite_id' => $this->worksite->id, 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '500']],
        ]);
        $quote = Quote::query()->firstOrFail();
        $ids = Photo::query()->pluck('id')->all();

        $this->get(route('quotes.show', $quote))->assertSee('Photos en annexe du PDF');
        $this->post(route('quotes.photos', $quote), ['photos' => $ids])->assertSessionHasNoErrors();
        $this->assertCount(2, $quote->fresh()->photos);

        $view = (new \ReflectionMethod(PdfService::class, 'viewData'))->invoke(app(PdfService::class), $quote->fresh());
        $this->assertStringContainsString('Photos du chantier', view('pdf.document', $view)->render());
        $this->assertStringStartsWith('%PDF-', app(PdfService::class)->render($quote->fresh()));

        // Photo d'un autre client : ignorée.
        $other = Photo::query()->first()->replicate();
        $other->worksite_id = Worksite::factory()->for(Client::factory())->create()->id;
        $other->save();
        $this->post(route('quotes.photos', $quote), ['photos' => [$other->id]]);
        $this->assertCount(0, $quote->fresh()->photos);

        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.photos', $quote), ['photos' => $ids])->assertForbidden();
    }

    public function test_client_documents_can_be_added_viewed_and_deleted(): void
    {
        $this->post(route('attachments.store', $this->client), [
            'files' => [UploadedFile::fake()->create('plan-toiture.pdf', 200, 'application/pdf')],
        ])->assertSessionHasNoErrors();

        $attachment = Attachment::query()->sole();
        $this->assertSame('plan-toiture.pdf', $attachment->name);
        $this->get(route('clients.show', $this->client))->assertSee('plan-toiture.pdf');
        $this->get(route('attachments.show', $attachment))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->post(route('attachments.store', $this->client), ['files' => [UploadedFile::fake()->create('script.php', 1)]])
            ->assertSessionHasErrors('files.0');

        $this->delete(route('attachments.destroy', $attachment));
        $this->assertModelMissing($attachment);
        Storage::disk('local')->assertMissing($attachment->path);
    }

    private function insurancePayload(array $overrides = []): array
    {
        return array_replace_recursive(['insurance' => [
            'insurer' => 'QBE Europe SA/NV', 'policy_number' => '037 0010701-D1002575',
            'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31',
            'activities' => 'Couverture', 'coverage_area' => 'France métropolitaine et DOM',
        ]], $overrides);
    }

    public function test_new_insurance_certificate_is_kept_in_history(): void
    {
        $this->get(route('settings.insurance'))->assertOk()->assertSee('Assurance décennale')->assertSee('Aucune attestation');

        $this->put(route('settings.insurance'), $this->insurancePayload() + [
            'certificate' => UploadedFile::fake()->create('attestation-2027.pdf', 120, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $certificate = InsuranceCertificate::query()->sole();
        $this->assertSame('2027-12-31', $certificate->valid_until->toDateString());
        Storage::disk('local')->assertExists($certificate->path);
        $this->assertSame('2027-12-31', app(Settings::class)->get('insurance.valid_until'));
        $this->get(route('settings.insurance'))->assertSee('du 01/01/2027 au 31/12/2027');
        $this->get(route('settings.insurance.certificate', $certificate))->assertOk();

        $this->put(route('settings.insurance'), $this->insurancePayload(['insurance' => ['valid_until' => '2026-01-01']]))
            ->assertSessionHasErrors('insurance.valid_until');
    }

    public function test_dashboard_warns_before_insurance_expiry(): void
    {
        $this->get(route('dashboard'))->assertDontSee('assurance décennale expire');

        Carbon::setTestNow('2026-12-20 09:00');
        $this->get(route('dashboard'))->assertSee('Votre assurance décennale expire dans 11 jours');

        Carbon::setTestNow('2027-01-02 09:00');
        $this->get(route('dashboard'))->assertSee('a expiré le 31/12/2026');
    }

    public function test_reminder_email_is_sent_15_and_5_days_before_expiry(): void
    {
        Mail::fake();
        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'password' => 'abcdabcdabcdabcd']);

        Carbon::setTestNow('2026-12-10 08:00');
        $this->artisan('app:insurance-reminder')->assertSuccessful();
        Mail::assertNothingSent();

        Carbon::setTestNow('2026-12-16 08:00');
        $this->artisan('app:insurance-reminder')->assertSuccessful();
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => $m->hasTo('mv.entreprise91@gmail.com') && str_contains($m->text, '15 jours'));
    }

    public function test_insurance_certificate_can_be_attached_to_an_email(): void
    {
        Mail::fake();
        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'password' => 'abcdabcdabcdabcd']);
        $this->put(route('settings.insurance'), $this->insurancePayload() + [
            'certificate' => UploadedFile::fake()->create('attestation.pdf', 50, 'application/pdf'),
        ]);

        $this->get(route('emails.create', ['client' => $this->client->id]))->assertSee("Joindre l'attestation d'assurance", false);
        $this->post(route('emails.store', ['client' => $this->client->id]), [
            'to' => 'client@example.com', 'subject' => 'Attestation', 'body' => 'Voici notre attestation.', 'attach_insurance' => '1',
        ])->assertSessionHasNoErrors();

        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => count($m->files) === 1
            && $m->files[0]['name'] === 'Attestation assurance décennale.pdf');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Photo;
use App\Models\Quote;
use App\Models\User;
use App\Models\Worksite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Tests\TestCase;

/** Photos visibles depuis le lien client, documents à signer sur place ou en ligne. */
class DocumentSignatureTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->client = Client::factory()->create(['last_name' => 'Martin', 'first_name' => 'Paul']);
        $this->actingAs($this->admin());
    }

    private function sentQuote(): Quote
    {
        $this->post(route('quotes.store'), ['client_id' => $this->client->id, 'title' => 'Toiture', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Réfection', 'quantity' => '1', 'unit_price' => '900']]])->assertSessionHasNoErrors();
        $quote = Quote::query()->latest('id')->firstOrFail();
        $this->post(route('quotes.send', $quote));

        return $quote->fresh();
    }

    private function pdfFile(string $name, int $pages = 1): UploadedFile
    {
        $mpdf = new Mpdf(['tempDir' => sys_get_temp_dir()]);
        for ($i = 1; $i <= $pages; $i++) {
            if ($i > 1) {
                $mpdf->AddPage();
            }
            $mpdf->WriteHTML("<h1>Autorisation de travaux — page $i</h1>");
        }
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $mpdf->Output('', 'S'));

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(300, 120);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imageline($image, 10, 60, 290, 70, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    private static function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    public function test_client_sees_the_photos_of_the_quote_from_its_link(): void
    {
        $worksite = Worksite::factory()->for($this->client)->create();
        $this->post(route('photos.store', $worksite), ['photos' => [UploadedFile::fake()->image('a.jpg', 800, 600), UploadedFile::fake()->image('b.jpg', 800, 600)],
            'category' => 'avant', 'caption' => 'Faîtage'])->assertSessionHasNoErrors();
        [$shown, $hidden] = Photo::query()->orderBy('id')->get()->all();
        $quote = $this->sentQuote();
        $this->post(route('quotes.photos', $quote), ['photos' => [$shown->id]])->assertSessionHasNoErrors();
        auth()->logout();

        $mini = route('portal.quote.photo', [$quote->public_token, $shown, 'mini']);
        $this->get(route('portal.quote', $quote->public_token))->assertOk()->assertSee('Photos')->assertSee($mini, false)->assertSee('Avant — Faîtage');
        $this->get($mini)->assertOk();
        $this->get(route('portal.quote.photo', [$quote->public_token, $shown, 'photo']))->assertOk();
        // Une photo non choisie pour ce devis, ou un mauvais lien : rien.
        $this->get(route('portal.quote.photo', [$quote->public_token, $hidden, 'mini']))->assertNotFound();
        $this->get(route('portal.quote.photo', [str_repeat('a', 40), $shown, 'mini']))->assertNotFound();
    }

    public function test_client_signs_online_a_document_attached_to_the_quote(): void
    {
        $quote = $this->sentQuote();
        $this->post(route('attachments.quote.store', $quote), ['files' => [$this->pdfFile('Autorisation.pdf', 2)]])->assertSessionHasNoErrors();
        $doc = Attachment::query()->sole();
        $this->get(route('quotes.show', $quote))->assertSee('Faire signer sur place')->assertSee('Faire signer en ligne');

        $this->post(route('attachments.sign.request', $doc))->assertRedirect(route('quotes.show', $quote).'#documents')->assertSessionHasNoErrors();
        $doc->refresh();
        $this->assertNotNull($doc->public_token);
        $this->assertSame(config('entreprise.client_url').'/doc/'.$doc->public_token, $doc->publicUrl());
        $this->get(route('quotes.show', $quote))->assertSee('À signer')->assertSee($doc->publicUrl());
        auth()->logout();

        // Sur le lien du devis, le client voit le document à signer.
        $this->get(route('portal.quote', $quote->public_token))->assertSee('Documents à signer')->assertSee(route('portal.document', $doc->public_token));
        $this->get(route('portal.document', $doc->public_token))->assertOk()->assertSee('Autorisation.pdf')->assertSee('Lu et approuvé');
        $this->assertNotNull($doc->fresh()->viewed_at);
        $this->get(route('portal.document.file', $doc->public_token))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->post(route('portal.document.sign', $doc->public_token), ['name' => 'Paul Martin', 'signature' => $this->signature()])
            ->assertSessionHasErrors('agree');
        $this->post(route('portal.document.sign', $doc->public_token), ['name' => 'Paul Martin', 'signature' => $this->signature(), 'agree' => '1'])
            ->assertRedirect(route('portal.document', $doc->public_token));

        $doc->refresh();
        $this->assertSame(['Paul Martin', false], [$doc->signed_name, $doc->signed_on_site]);
        $this->assertSame(64, strlen($doc->original_hash));
        $signed = Storage::disk('local')->get($doc->signed_path);
        $this->assertSame(3, self::pageCount($signed), 'Les 2 pages d\'origine puis la page de signature.');
        Storage::disk('local')->assertExists($doc->path);

        $this->get(route('portal.document', $doc->public_token))->assertSee('Document signé')->assertDontSee('Lu et approuvé');
        $this->assertSame($signed, $this->get(route('portal.document.file', $doc->public_token))->streamedContent());
        $this->get(route('portal.quote', $quote->public_token))->assertSee('Signé le');
        // Déjà signé : une seconde signature ne change rien.
        $this->post(route('portal.document.sign', $doc->public_token), ['name' => 'Autre', 'signature' => $this->signature(), 'agree' => '1']);
        $this->assertSame('Paul Martin', $doc->fresh()->signed_name);

        $this->actingAs($this->admin());
        $this->get(route('quotes.show', $quote))->assertSee('Signé en ligne par');
        $this->assertSame($signed, $this->get(route('attachments.show', $doc))->streamedContent());
        $this->assertNotSame($signed, $this->get(route('attachments.show', [$doc, 'original' => 1]))->streamedContent());
    }

    public function test_photo_of_a_document_on_the_client_file_is_signed_on_site(): void
    {
        $this->post(route('attachments.store', $this->client), ['files' => [UploadedFile::fake()->image('bon.jpg', 1200, 1600)]])
            ->assertRedirect(route('clients.show', $this->client).'#documents');
        $doc = Attachment::query()->sole();
        $this->assertNull($doc->quote_id);
        $this->get(route('clients.show', $this->client))->assertSee('bon.jpg')->assertSee('Faire signer sur place')->assertSee('Scanner un document');

        $this->get(route('attachments.sign.on-site', $doc))->assertOk()->assertSee('bon.jpg')->assertSee('data-signature-canvas', false);
        $this->post(route('attachments.sign.on-site.store', $doc), ['name' => 'Paul Martin', 'signature' => $this->signature(), 'agree' => '1'])
            ->assertRedirect(route('clients.show', $this->client).'#documents');

        $doc->refresh();
        $this->assertTrue($doc->signed_on_site);
        $this->assertNull($doc->signed_ip);
        $this->assertSame(2, self::pageCount(Storage::disk('local')->get($doc->signed_path)));
        $this->assertSame('bon-signé.pdf', $doc->currentName());
        $this->get(route('attachments.sign.image', $doc))->assertOk();
        $this->get(route('clients.show', $this->client))->assertSee('Signé sur place par');

        // Suppression : le document, la signature et la version signée.
        $this->delete(route('attachments.destroy', $doc));
        Storage::disk('local')->assertMissing([$doc->path, $doc->signed_path, $doc->signature_path]);
    }

    public function test_unreadable_pdf_cannot_be_sent_for_signature_and_link_can_be_cancelled(): void
    {
        $this->post(route('attachments.store', $this->client), ['files' => [UploadedFile::fake()->create('protege.pdf', 10, 'application/pdf')]]);
        $broken = Attachment::query()->sole();
        $this->post(route('attachments.sign.request', $broken))->assertSessionHasErrors('signature_'.$broken->id);
        $this->assertNull($broken->fresh()->public_token);

        $this->post(route('attachments.store', $this->client), ['files' => [$this->pdfFile('Plan.pdf')]]);
        $doc = Attachment::query()->latest('id')->firstOrFail();
        $this->post(route('attachments.sign.request', $doc))->assertSessionHasNoErrors();
        $token = $doc->fresh()->public_token;
        $this->get(route('portal.document', $token))->assertOk();

        $this->delete(route('attachments.sign.cancel', $doc));
        $this->get(route('portal.document', $token))->assertNotFound();
        $this->get(route('portal.document.file', $token))->assertNotFound();
    }

    public function test_recent_compressed_pdf_is_signed_and_protected_pdf_is_explained(): void
    {
        $fixture = fn (string $name) => new UploadedFile(base_path('tests/fixtures/'.$name), $name, 'application/pdf', null, true);
        $this->post(route('attachments.store', $this->client), ['files' => [$fixture('pdf-compresse.pdf'), $fixture('pdf-protege.pdf')]]);
        [$recent, $protected] = Attachment::query()->orderBy('id')->get()->all();

        $this->post(route('attachments.sign.on-site.store', $recent), ['name' => 'Paul Martin', 'signature' => $this->signature(), 'agree' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, self::pageCount(Storage::disk('local')->get($recent->fresh()->signed_path)));

        $this->post(route('attachments.sign.request', $protected))
            ->assertSessionHasErrors(['signature_'.$protected->id => 'Ce PDF est protégé ou abîmé et ne peut pas être signé. Imprimez-le puis scannez-le avec « Scanner un document ».']);
        $this->post(route('attachments.sign.on-site.store', $protected), ['name' => 'Paul Martin', 'signature' => $this->signature(), 'agree' => '1'])
            ->assertSessionHasErrors('signature');
        $this->assertNull($protected->fresh()->signed_at);
    }

    public function test_commercial_cannot_have_an_invoice_document_signed(): void
    {
        $quote = $this->sentQuote();
        $this->post(route('quotes.accept', $quote));
        $this->post(route('quotes.invoice', $quote), ['kind' => 'standard']);
        $invoice = Invoice::query()->sole();
        $this->post(route('attachments.invoice.store', $invoice), ['files' => [$this->pdfFile('Facture-annexe.pdf')]])->assertSessionHasNoErrors();
        $doc = Attachment::query()->sole();
        $this->get(route('invoices.show', $invoice))->assertSee('Faire signer en ligne');

        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->post(route('attachments.sign.request', $doc))->assertForbidden();
        $this->get(route('attachments.sign.on-site', $doc))->assertForbidden();
        $this->delete(route('attachments.destroy', $doc))->assertForbidden();
        $this->get(route('clients.show', $this->client))->assertOk()->assertDontSee('Facture-annexe.pdf');
    }
}

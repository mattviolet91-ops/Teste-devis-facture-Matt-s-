<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\WixArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mpdf\Mpdf;
use Tests\TestCase;
use ZipArchive;

class WixArchiveTest extends TestCase
{
    use RefreshDatabase;

    /** PDF au format des devis Wix (données fictives). */
    private function wixPdf(string $number, string $status, string $email): string
    {
        $mpdf = new Mpdf(['tempDir' => storage_path('framework/cache/mpdf')]);
        $mpdf->WriteHTML(implode('<br>', [
            "Matt's Couverture", '8 Chemin de la Plesse', '91140, Villebon-sur-Yvette', 'France', 'mv.entreprise91@gmail.com',
            'Téléphone : 07 67 92 68 36', "Numéro d'identification de la société :", '98170816700011',
            'Devis nettoyage Madame Test', 'Julie Test', $email, '12 rue des Lilas', 'Massy, 91300', '6 11 22 33 44',
            $status, "Devis N° $number", 'Émis le : 12 mars 2026', 'Expire le : 11 avr. 2026',
            'Traitement de la toiture 100 9,90 € 990,00 €', 'Prix total : 1 990,50 €',
        ]));

        return $mpdf->Output('', 'S');
    }

    public function test_wix_quotes_are_archived_on_the_right_client(): void
    {
        $this->actingAs($this->admin());
        $existing = Client::factory()->create(['email' => 'julie.test@example.org']);

        $dir = sys_get_temp_dir().'/wix-'.uniqid();
        mkdir($dir);
        file_put_contents("$dir/a.pdf", $this->wixPdf('0002288', 'Facturé', 'julie.test@example.org'));
        $zipPath = "$dir/devis.zip";
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('Devis n° 0002290.pdf', $this->wixPdf('0002290', 'Expiré', 'nouveau.client@example.org'));
        $zip->addFromString('Devis n° 0002290 (1).pdf', $this->wixPdf('0002290', 'Expiré', 'nouveau.client@example.org'));
        $zip->close();

        $this->post(route('archives.store'), ['files' => [
            new UploadedFile("$dir/a.pdf", 'Devis n° 0002288.pdf', 'application/pdf', null, true),
            new UploadedFile($zipPath, 'devis.zip', 'application/zip', null, true),
        ]])->assertRedirect(route('archives.index'));

        $this->assertSame(2, WixArchive::query()->count());
        $first = WixArchive::query()->where('number', '0002288')->firstOrFail();
        $this->assertSame($existing->id, $first->client_id);
        $this->assertSame('Facturé', $first->status);
        $this->assertSame('2026-03-12', $first->issue_date->toDateString());
        $this->assertSame(199050, $first->total);
        $this->assertSame('Devis nettoyage Madame Test', $first->title);

        $created = WixArchive::query()->where('number', '0002290')->firstOrFail()->client;
        $this->assertSame('nouveau.client@example.org', $created->email);
        $this->assertSame('Test', $created->last_name);
        $this->assertSame('91300', $created->postal_code);

        $this->get(route('archives.index'))->assertOk()->assertSee('Devis Wix n° 0002288');
        $this->get(route('clients.show', $existing))->assertSee('Historique Wix');
        $this->get(route('archives.show', $first))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Nouvel import des mêmes documents : ignorés.
        file_put_contents("$dir/b.pdf", $this->wixPdf('0002288', 'Facturé', 'julie.test@example.org'));
        $this->post(route('archives.store'), ['files' => [new UploadedFile("$dir/b.pdf", 'b.pdf', 'application/pdf', null, true)]]);
        $this->assertSame(2, WixArchive::query()->count());
    }

    public function test_non_wix_pdf_is_reported(): void
    {
        $this->actingAs($this->admin());
        $mpdf = new Mpdf(['tempDir' => storage_path('framework/cache/mpdf')]);
        $mpdf->WriteHTML('Un simple courrier');
        $path = sys_get_temp_dir().'/courrier-'.uniqid().'.pdf';
        file_put_contents($path, $mpdf->Output('', 'S'));

        $this->post(route('archives.store'), ['files' => [new UploadedFile($path, 'courrier.pdf', 'application/pdf', null, true)]])
            ->assertSessionHas('archive_errors', fn ($errors) => str_contains($errors[0], 'ni devis ni facture'));
    }

    public function test_new_client_sources_are_available(): void
    {
        $this->actingAs($this->admin());
        $this->get(route('clients.create'))->assertSee('Publicité')->assertSee('Stand en magasin');
    }
}

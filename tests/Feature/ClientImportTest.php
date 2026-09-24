<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Worksite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ClientImportTest extends TestCase
{
    use RefreshDatabase;

    private function wixCsv(): UploadedFile
    {
        $csv = "\u{FEFF}Prénom,Nom de famille,E-mail 1,E-mail 2,E-mail 3,Téléphone 1,Téléphone 2,Adresse 1 - Type,Adresse 1 - Rue,Adresse 1 - Rue ligne 2,Adresse 1 - Ville,Adresse 1 - État/Région,Adresse 1 - Code postal,Adresse 1 - Pays,Libellés,Créé le (UTC+0),Source,Dernière activité,Message,Commentaires\n"
            ."hélène,Dupont,helene@example.com,,,'+33 6 12 34 56 78,,,12 rue des Tilleuls,,Massy,,91300,France,Obtenir un devis Nettoyage,2025-03-02 10:51,Envoi d'un formulaire,A payé une facture,\"Bonjour, mousse sur le toit\",Client sympa\n"
            ."Paul,,paul@example.com,,,612345679,,,\"16 av. Charles de Gaulle 91140 Villebon-sur-Yvette\",,,,,,,2024-01-10 08:00,Création manuelle,Contact créé,,\n"
            ."Hélène,Dupont bis,HELENE@example.com,,,,,,,,,,,,,2024-01-10 08:00,Création manuelle,,,\n"
            ."Jean,Martin,,,,ss,,,,,,,,,,2024-01-10 08:00,Création manuelle,,,\n"
            .",,,,,,,,,,,,,,,2024-01-10 08:00,Création manuelle,,,\n";

        return UploadedFile::fake()->createWithContent('contacts.csv', $csv);
    }

    public function test_wix_export_is_previewed_then_imported_without_duplicates(): void
    {
        $this->actingAs($this->admin());
        Client::factory()->create(['phone' => '06 99 99 99 99', 'email' => 'deja@example.com']);

        $this->get(route('clients.index'))->assertSee('Importer');
        $this->get(route('clients.import'))->assertOk();

        $response = $this->post(route('clients.import.preview'), ['file' => $this->wixCsv()]);
        $response->assertOk()->assertSee('3</strong> nouveau(x) client(s)', false)->assertSee('1 doublon(s) ignoré(s)')->assertSee('1 ligne(s) vide(s)');
        $this->assertSame(1, Client::query()->count(), 'Rien n\'est créé avant la confirmation.');

        $token = $response->viewData('token');
        $this->post(route('clients.import.store'), ['token' => $token])->assertRedirect(route('clients.index'));

        $helene = Client::query()->where('email', 'helene@example.com')->firstOrFail();
        $this->assertSame('Hélène', $helene->first_name);
        $this->assertSame('06 12 34 56 78', $helene->phone);
        $this->assertSame('client', $helene->status, 'A payé une facture sur Wix.');
        $this->assertSame('site', $helene->source);
        $this->assertSame('2025-03-02', $helene->created_at->toDateString());
        $this->assertStringContainsString('mousse sur le toit', $helene->notes);
        $this->assertSame(1, Worksite::query()->where('client_id', $helene->id)->count());

        $paul = Client::query()->where('email', 'paul@example.com')->firstOrFail();
        $this->assertSame('Paul', $paul->last_name, 'Prénom seul : utilisé comme nom.');
        $this->assertSame('06 12 34 56 79', $paul->phone, 'Zéro initial rétabli.');
        $this->assertSame('91140', $paul->postal_code);
        $this->assertSame('Villebon-sur-Yvette', $paul->city);

        $jean = Client::query()->where('last_name', 'Martin')->firstOrFail();
        $this->assertNull($jean->phone);
        $this->assertStringContainsString('Téléphone indiqué sur Wix : ss', $jean->notes);

        // Second import du même fichier : plus rien à créer.
        $this->post(route('clients.import.preview'), ['file' => $this->wixCsv()])->assertSee('Aucun nouveau client à importer');
    }

    public function test_invalid_file_is_refused(): void
    {
        $this->actingAs($this->admin());

        $this->post(route('clients.import.preview'), ['file' => UploadedFile::fake()->createWithContent('x.csv', "a,b\n1,2\n")])
            ->assertSessionHasErrors('file');
        $this->post(route('clients.import.store'), ['token' => str_repeat('a', 32)])->assertNotFound();
    }

    public function test_import_requires_login(): void
    {
        $this->get(route('clients.import'))->assertRedirect(route('login'));
    }
}

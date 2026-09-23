<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\NumberSequence;
use App\Models\Worksite;
use App\Services\NumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Corrections demandées après la revue de la phase 5. */
class CorrectionsPhase5Test extends TestCase
{
    use RefreshDatabase;

    private function numberingPayload(int $quoteNext): array
    {
        return [
            'quote_validity_days' => 30,
            'sequences' => [
                'quote' => ['prefix' => 'DEV', 'next_number' => $quoteNext],
                'invoice' => ['prefix' => 'FAC', 'next_number' => 1],
                'credit_note' => ['prefix' => 'AV', 'next_number' => 1],
            ],
        ];
    }

    public function test_next_number_cannot_go_back_below_an_issued_number(): void
    {
        $generator = app(NumberGenerator::class);
        foreach (range(1, 15) as $ignored) {
            $generator->next('quote');
        }

        $admin = $this->admin();
        $this->actingAs($admin)->put(route('settings.numbering'), $this->numberingPayload(3))
            ->assertSessionHasErrors('sequences.quote.next_number');
        $this->assertStringContainsString('15 a déjà été attribué', session('errors')->first('sequences.quote.next_number'));
        $this->assertSame(16, NumberSequence::query()->where('type', 'quote')->value('next_number'));

        // Sauter des numéros vers le haut reste possible.
        $this->actingAs($admin)->put(route('settings.numbering'), $this->numberingPayload(100))->assertSessionHasNoErrors();
        $this->assertStringEndsWith('-0100', $generator->next('quote'));
    }

    public function test_next_number_can_be_lowered_while_nothing_was_issued(): void
    {
        NumberSequence::query()->where('type', 'quote')->update(['next_number' => 50]);

        $this->actingAs($this->admin())->put(route('settings.numbering'), $this->numberingPayload(1))->assertSessionHasNoErrors();
    }

    public function test_history_keeps_before_and_after_values(): void
    {
        $client = Client::factory()->create(['last_name' => 'Durand', 'phone' => '06 11 11 11 11', 'type' => 'particulier']);

        $this->actingAs($this->admin())->put(route('clients.update', $client), [
            'type' => 'particulier', 'last_name' => 'Durand', 'phone' => '0622222222', 'status' => 'client',
        ]);

        $log = ActivityLog::query()->where('action', 'client.updated')->firstOrFail();
        $this->assertContains(['champ' => 'Téléphone', 'avant' => '06 11 11 11 11', 'apres' => '06 22 22 22 22'], $log->properties['modifications']);
        $this->assertContains(['champ' => 'Statut', 'avant' => 'Prospect', 'apres' => 'Client'], $log->properties['modifications']);

        $this->get(route('clients.show', $client))->assertSee('06 11 11 11 11')->assertSee('06 22 22 22 22');
    }

    public function test_worksite_history_uses_readable_values(): void
    {
        $worksite = Worksite::factory()->create(['roof_type' => 'ardoise', 'postal_code' => '91300']);

        $this->actingAs($this->admin())->put(route('worksites.update', $worksite), [
            'address' => $worksite->address, 'postal_code' => '91300', 'city' => $worksite->city, 'roof_type' => 'zinc',
            'roof_surface' => $worksite->roof_surface, 'levels' => $worksite->levels,
        ]);

        $changes = ActivityLog::query()->where('action', 'worksite.updated')->firstOrFail()->properties['modifications'];
        $this->assertSame([['champ' => 'Couverture', 'avant' => 'Ardoise', 'apres' => 'Zinc']], $changes);
    }

    public function test_postal_code_must_have_five_digits(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('clients.store'), ['type' => 'particulier', 'last_name' => 'X', 'postal_code' => '9130'])
            ->assertSessionHasErrors(['postal_code' => 'Le code postal doit comporter 5 chiffres.']);

        $this->actingAs($admin)->post(route('worksites.store', Client::factory()->create()), [
            'address' => '1 rue A', 'postal_code' => 'abcde', 'city' => 'Massy',
        ])->assertSessionHasErrors('postal_code');

        $this->actingAs($admin)->post(route('clients.store'), ['type' => 'particulier', 'last_name' => 'Y', 'postal_code' => '91 300'])
            ->assertSessionHasNoErrors();
        $this->assertSame('91300', Client::query()->where('last_name', 'Y')->value('postal_code'));
    }

    public function test_search_words_can_match_client_and_worksite_together(): void
    {
        $client = Client::factory()->create(['last_name' => 'Dupont', 'city' => 'Orsay']);
        Worksite::factory()->for($client)->create(['city' => 'Massy']);
        Client::factory()->create(['last_name' => 'Dupont', 'city' => 'Palaiseau']);

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('search', ['q' => 'dupont massy']))->assertSee('Orsay')->assertDontSee('Palaiseau');
        $this->actingAs($admin)->get(route('clients.index', ['q' => 'dupont massy']))->assertSee('1 fiche');
    }

    public function test_duplicate_phone_or_email_asks_for_confirmation(): void
    {
        Client::factory()->create(['last_name' => 'Dupont', 'phone' => '06 12 34 56 78', 'email' => 'dupont@example.com']);
        $admin = $this->admin();
        $payload = ['type' => 'particulier', 'last_name' => 'Nouveau', 'phone' => '+33 6 12 34 56 78'];

        $this->actingAs($admin)->from(route('clients.create'))->post(route('clients.store'), $payload)
            ->assertRedirect(route('clients.create'))
            ->assertSessionHas('duplicates', fn ($d) => count($d) === 1 && str_contains($d[0]['name'], 'Dupont'));
        $this->assertSame(1, Client::query()->count());

        $this->actingAs($admin)->post(route('clients.store'), ['type' => 'particulier', 'last_name' => 'Autre', 'email' => 'DUPONT@example.com'])
            ->assertSessionHas('duplicates');

        $this->actingAs($admin)->post(route('clients.store'), $payload + ['confirm_duplicate' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(2, Client::query()->count());
    }

    public function test_partial_phone_match_is_not_a_duplicate(): void
    {
        Client::factory()->create(['phone' => '06 12 34 56 78', 'email' => null]);

        $this->actingAs($this->admin())->post(route('clients.store'), ['type' => 'particulier', 'last_name' => 'X', 'phone' => '06 12 34 56 79'])
            ->assertSessionMissing('duplicates');
        $this->assertSame(2, Client::query()->count());
    }

    public function test_clients_can_be_sorted_alphabetically(): void
    {
        Client::factory()->create(['last_name' => 'Zola']);
        Client::factory()->company('syndic')->create(['company_name' => 'Brunet Syndic']);
        Client::factory()->create(['last_name' => 'Arnaud']);

        $this->actingAs($this->admin())->get(route('clients.index', ['sort' => 'az']))
            ->assertSeeInOrder(['Arnaud', 'Brunet Syndic', 'Zola']);
    }

    public function test_remember_me_is_unchecked_by_default(): void
    {
        $this->get(route('login'))->assertDontSee('name="remember" value="1" checked', false);
    }

    public function test_address_api_is_allowed_by_content_security_policy(): void
    {
        $this->assertStringContainsString('https://data.geopf.fr', $this->get(route('login'))->headers->get('Content-Security-Policy'));
    }

    public function test_favicon_is_a_real_icon(): void
    {
        $this->assertGreaterThan(100, filesize(public_path('favicon.ico')));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Intervention;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/** Compte commercial : ses devis, ses factures et son agenda ; le gérant voit tout. */
class CommercialScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $seller;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 10:00');
        Storage::fake('local');
        $this->manager = $this->admin();
        $this->seller = User::factory()->create(['role' => 'commercial', 'name' => 'Léo Vendeur']);
        $this->client = Client::factory()->create(['last_name' => 'Martin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function quoteBy(User $user, string $title): Quote
    {
        $this->actingAs($user)->post(route('quotes.store'), ['client_id' => $this->client->id, 'title' => $title, 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => $title, 'quantity' => '1', 'unit_price' => '1000']]])->assertSessionHasNoErrors();
        $quote = Quote::query()->latest('id')->firstOrFail();
        $this->post(route('quotes.send', $quote));

        return $quote->fresh();
    }

    public function test_commercial_only_sees_his_own_quotes_and_invoices_and_the_manager_sees_everything(): void
    {
        $mine = $this->quoteBy($this->seller, 'Gouttières du commercial');
        $theirs = $this->quoteBy($this->manager, 'Toiture du gérant');
        $this->post(route('quotes.accept', $theirs));
        $this->post(route('quotes.invoice', $theirs), ['kind' => 'standard']);
        $managerInvoice = Invoice::query()->sole();
        $this->post(route('attachments.quote.store', $theirs), ['files' => [UploadedFile::fake()->image('plan.jpg', 400, 300)]]);
        $managerDoc = Attachment::query()->sole();

        // Le gérant voit tout, avec le nom du commercial sur ses documents.
        $this->get(route('quotes.index'))->assertSee('Gouttières du commercial')->assertSee('Toiture du gérant')->assertSee('Léo Vendeur');

        $this->actingAs($this->seller);
        $this->get(route('quotes.index'))->assertSee('Gouttières du commercial')->assertDontSee('Toiture du gérant');
        $this->get(route('clients.show', $this->client))->assertSee($mine->number)->assertDontSee($theirs->number)->assertDontSee('plan.jpg');
        $this->get(route('search', ['q' => 'Martin']))->assertSee($mine->number)->assertDontSee($theirs->number);
        $this->get(route('invoices.index'))->assertDontSee(route('invoices.show', $managerInvoice));

        // Adresse ouverte directement : refusée, PDF, email, document et signature compris.
        foreach ([route('quotes.show', $theirs), route('quotes.pdf', $theirs), route('quotes.edit', $theirs), route('quotes.on-site', $theirs),
            route('emails.create', ['devis' => $theirs->id]), route('invoices.show', $managerInvoice), route('invoices.pdf', $managerInvoice),
            route('attachments.show', $managerDoc)] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post(route('quotes.duplicate', $theirs), ['client_id' => $this->client->id])->assertForbidden();

        // Ce qu'il crée lui appartient : copie de son devis et facture de son devis.
        $this->post(route('quotes.duplicate', $mine), ['client_id' => $this->client->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($this->seller->id, Quote::query()->latest('id')->first()->created_by);
        $this->post(route('quotes.accept', $mine));
        $this->post(route('quotes.invoice', $mine), ['kind' => 'standard'])->assertRedirect();
        $own = Invoice::query()->latest('id')->first();
        $this->assertSame($this->seller->id, $own->created_by);
        $this->get(route('invoices.index'))->assertSee(route('invoices.show', $own));
        $this->get(route('invoices.show', $own))->assertOk();

        // Le gérant ouvre tout.
        $this->actingAs($this->manager);
        $this->get(route('invoices.index'))->assertSee(route('invoices.show', $own))->assertSee(route('invoices.show', $managerInvoice))->assertSee('Léo Vendeur');
        $this->get(route('quotes.show', $mine))->assertOk();
    }

    public function test_agenda_belongs_to_each_person_and_the_manager_sees_and_fills_the_commercial_one(): void
    {
        $this->actingAs($this->manager);
        $this->get(route('planning.create', ['type' => 'rdv']))->assertSee('Pour qui')->assertSee('Léo Vendeur (commercial)');

        // Le gérant ajoute un rendez-vous et un rappel au commercial.
        $this->post(route('planning.store'), ['kind' => 'rdv', 'user_id' => $this->seller->id, 'client_id' => $this->client->id, 'title' => 'Métré pour Léo',
            'starts_on' => '2026-11-17', 'start_time' => '09:00'])->assertSessionHasNoErrors();
        $this->post(route('planning.store'), ['kind' => 'rappel', 'user_id' => $this->seller->id, 'title' => 'Rappeler M. Martin',
            'starts_on' => '2026-11-16', 'start_time' => '14:30', 'notes' => 'Pour le devis'])->assertSessionHasNoErrors();
        $this->post(route('planning.store'), ['kind' => 'rdv', 'user_id' => $this->manager->id, 'client_id' => $this->client->id, 'title' => 'Expertise du gérant',
            'starts_on' => '2026-11-17', 'start_time' => '11:00'])->assertSessionHasNoErrors();
        [$forSeller, $reminder, $managerRdv] = Intervention::query()->orderBy('id')->get()->all();
        $this->assertSame([$this->seller->id, $this->seller->id, $this->manager->id], [$forSeller->user_id, $reminder->user_id, $managerRdv->user_id]);
        $this->assertSame(['rappel', null, 0], [$reminder->kind, $reminder->client_id, $reminder->remind_days]);
        // Un rappel sans heure : refusé.
        $this->post(route('planning.store'), ['kind' => 'rappel', 'title' => 'Sans heure', 'starts_on' => '2026-11-16'])->assertSessionHasErrors('start_time');

        // Gérant : tout, ou seulement l'agenda du commercial.
        $this->get(route('planning.index'))->assertSee('Métré pour Léo')->assertSee('Rappeler M. Martin')->assertSee('Expertise du gérant');
        $this->get(route('planning.index', ['pour' => $this->seller->id]))->assertSee('Métré pour Léo')->assertDontSee('Expertise du gérant');

        // Commercial : seulement le sien.
        $this->actingAs($this->seller);
        $this->get(route('planning.index'))->assertSee('Métré pour Léo')->assertSee('Rappeler M. Martin')->assertDontSee('Expertise du gérant');
        $this->get(route('planning.show', $managerRdv))->assertForbidden();
        $this->get(route('planning.edit', $managerRdv))->assertForbidden();
        $this->get(route('planning.create'))->assertDontSee('Pour qui');
        // Il ne peut rien mettre dans l'agenda du gérant.
        $this->post(route('planning.store'), ['kind' => 'rdv', 'user_id' => $this->manager->id, 'title' => 'Essai', 'starts_on' => '2026-11-18', 'start_time' => '10:00'])
            ->assertSessionHasErrors('user_id');
        $this->post(route('planning.store'), ['kind' => 'rappel', 'title' => 'Passer chez le fournisseur', 'starts_on' => '2026-11-18', 'start_time' => '08:00'])->assertSessionHasNoErrors();
        $this->assertSame($this->seller->id, Intervention::query()->latest('id')->first()->user_id);
    }

    public function test_notifications_reach_the_person_concerned_and_always_the_manager(): void
    {
        Intervention::query()->create(['user_id' => $this->seller->id, 'kind' => 'rappel', 'title' => 'Rappeler M. Martin',
            'starts_on' => '2026-11-16', 'ends_on' => '2026-11-16', 'start_time' => '09:55', 'remind_days' => 0]);
        Intervention::query()->create(['user_id' => $this->manager->id, 'kind' => 'rdv', 'title' => 'RDV du gérant',
            'starts_on' => '2026-11-16', 'ends_on' => '2026-11-16', 'start_time' => '10:30', 'end_time' => '11:30']);

        $push = Mockery::mock(PushService::class);
        $push->shouldReceive('send')->once()->withArgs(fn ($title, $body, $url, $user = null) => $title === 'Rappel : Rappeler M. Martin' && $user === null)->andReturn(1);
        $push->shouldReceive('send')->once()->withArgs(fn ($title, $body, $url, $user = null) => $title === 'Rappel : Rappeler M. Martin' && $user === $this->seller->id)->andReturn(1);
        $push->shouldReceive('send')->once()->withArgs(fn ($title, $body, $url, $user = null) => str_starts_with($title, 'Rendez-vous à 10h30') && $user === null)->andReturn(1);
        $this->app->instance(PushService::class, $push);

        $this->artisan('app:planning-notifications --bientot')->assertSuccessful();
        // Déjà envoyé : pas une seconde fois.
        $this->artisan('app:planning-notifications --bientot')->assertSuccessful();
    }
}

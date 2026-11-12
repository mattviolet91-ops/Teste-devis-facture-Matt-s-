<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\Photo;
use App\Models\Quote;
use App\Models\User;
use App\Models\Worksite;
use App\Services\InvoiceService;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Corrections de l'audit de novembre 2026. */
class AuditFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs($this->admin());
    }

    private function trashLongAgo(Client $client): void
    {
        $client->delete();
        DB::table('clients')->where('id', $client->id)->update(['deleted_at' => now()->subDays(40)]);
    }

    public function test_client_with_a_pending_payment_link_cannot_be_trashed(): void
    {
        $client = Client::factory()->create();
        $this->post(route('payments.requests.store'), ['client_id' => $client->id, 'label' => 'Dépannage', 'amount' => '90', 'mode' => 'card'])->assertSessionHasNoErrors();

        $this->delete(route('clients.destroy', $client))->assertSessionHasErrors('client');
        $this->assertNotSoftDeleted($client);

        $this->delete(route('payments.requests.cancel', PaymentRequest::query()->sole()));
        $this->delete(route('clients.destroy', $client))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($client);
    }

    public function test_purge_removes_the_client_files_and_unpaid_payment_links(): void
    {
        $client = Client::factory()->create();
        $worksite = Worksite::factory()->for($client)->create();
        $this->post(route('attachments.store', $client), ['files' => [UploadedFile::fake()->image('plan.jpg', 600, 400)]]);
        $this->post(route('photos.store', $worksite), ['photos' => [UploadedFile::fake()->image('toit.jpg', 800, 600)], 'category' => 'avant']);
        $this->post(route('payments.requests.store'), ['client_id' => $client->id, 'label' => 'Dépannage', 'amount' => '90', 'mode' => 'card']);
        $this->delete(route('payments.requests.cancel', PaymentRequest::query()->sole()));
        $files = [Attachment::query()->sole()->path, Photo::query()->sole()->path, Photo::query()->sole()->thumb_path];
        Storage::disk('local')->assertExists($files);

        $this->trashLongAgo($client);
        $this->artisan('app:purge-trash')->assertSuccessful();

        $this->assertNull(Client::withTrashed()->find($client->id));
        $this->assertSame([0, 0, 0], [PaymentRequest::query()->count(), Attachment::query()->count(), Photo::query()->count()]);
        Storage::disk('local')->assertMissing($files);
    }

    public function test_one_client_that_cannot_be_purged_does_not_block_the_others(): void
    {
        $blocked = Client::factory()->create();
        $free = Client::factory()->create();
        // Devis restauré après la mise à la corbeille du client : le client doit rester.
        $this->post(route('quotes.store'), ['client_id' => $blocked->id, 'title' => 'Toiture', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Réfection', 'quantity' => '1', 'unit_price' => '900']]]);
        $this->assertSame(1, Quote::query()->count());
        $this->trashLongAgo($blocked);
        $this->trashLongAgo($free);

        $this->artisan('app:purge-trash')->expectsOutputToContain('Conservé : client '.$blocked->id)->assertSuccessful();

        $this->assertNotNull(Client::withTrashed()->find($blocked->id));
        $this->assertNull(Client::withTrashed()->find($free->id));
    }

    public function test_final_invoice_draft_follows_later_deposits_and_quote_total_cannot_be_exceeded(): void
    {
        $client = Client::factory()->create();
        $this->post(route('quotes.store'), ['client_id' => $client->id, 'title' => 'Toiture', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Réfection', 'quantity' => '1', 'unit_price' => '2700']]]);
        $quote = Quote::query()->sole();
        $this->post(route('quotes.send', $quote));
        $this->post(route('quotes.accept', $quote));

        $this->post(route('quotes.invoice', $quote), ['kind' => 'deposit', 'percent' => '30']);
        $deposit = Invoice::query()->sole();
        $this->post(route('invoices.send', $deposit))->assertSessionHasNoErrors();
        $this->post(route('quotes.invoice', $quote), ['kind' => 'final']);
        $final = Invoice::query()->where('kind', 'final')->sole();
        $this->assertSame(189000, $final->total_ttc);

        // Second acompte facturé après la préparation du solde : le brouillon de solde suit.
        $this->post(route('quotes.invoice', $quote), ['kind' => 'deposit', 'percent' => '10']);
        $second = Invoice::query()->where('kind', 'deposit')->latest('id')->first();
        $this->post(route('invoices.send', $second))->assertSessionHasNoErrors();
        $this->assertSame(162000, $final->fresh()->total_ttc);
        $this->assertSame(2, $final->fresh()->lines()->where('title', 'like', 'Déduction %')->count());

        // Un brouillon modifié à la main qui dépasserait le devis ne peut pas être envoyé.
        $final->lines()->where('title', 'like', 'Déduction %')->delete();
        app(InvoiceService::class)->recalculate($final);
        $this->post(route('invoices.send', $final))->assertSessionHasErrors();
        $this->assertSame('draft', $final->fresh()->status);
        $this->get(route('invoices.show', $final))->assertSee('dépasserait le montant du devis');

        // Remis à jour, il part.
        app(InvoiceService::class)->refreshFinalDrafts($quote);
        $this->post(route('invoices.send', $final))->assertSessionHasNoErrors();
        $this->assertSame(['sent', 162000], [$final->fresh()->status, $final->fresh()->total_ttc]);
        $this->assertSame(270000, (int) $quote->invoices()->whereIn('status', Invoice::ISSUED)->sum('total_ttc'));
    }

    public function test_salesman_sees_no_link_to_the_email_settings_and_card_attempts_show_the_label(): void
    {
        $client = Client::factory()->create(['phone' => '0612345678']);
        $this->post(route('quotes.store'), ['client_id' => $client->id, 'title' => 'Toiture', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Réfection', 'quantity' => '1', 'unit_price' => '900']]]);
        $quote = Quote::query()->sole();
        $this->post(route('quotes.send', $quote));
        $this->get(route('quotes.show', $quote))->assertSee(route('settings.emails'));

        app(Settings::class)->set(['mypos.enabled' => true, 'mypos.test' => true]);
        $this->post(route('payments.requests.store'), ['client_id' => $client->id, 'label' => 'Petit dépannage', 'amount' => '90', 'mode' => 'card']);
        $this->get(route('portal.payment-request.pay', ['token' => PaymentRequest::query()->sole()->token, 'essai' => 1]))->assertOk();
        $this->get(route('settings.payments'))->assertSee('Petit dépannage · '.$client->displayName());

        $this->actingAs(User::factory()->create(['role' => 'commercial']));
        $this->get(route('quotes.show', $quote))->assertOk()->assertDontSee(route('settings.emails'));
    }
}

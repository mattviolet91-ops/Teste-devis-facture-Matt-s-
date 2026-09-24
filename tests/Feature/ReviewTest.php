<?php

namespace Tests\Feature;

use App\Mail\ClientMessage;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\ReviewRequest;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private const GOOGLE = 'https://g.page/r/CabcDEF123/review';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00');
        Mail::fake();
        $this->actingAs($this->admin());
        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'password' => 'abcdabcdabcdabcd']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enable(): void
    {
        $this->put(route('settings.emails.reviews'), [
            'enabled' => '1', 'google_url' => self::GOOGLE, 'delay_days' => 2,
            'review_subject' => 'Votre avis compte pour nous', 'review' => "{salutation}\nMerci ! Laissez-nous un avis :\n{lien_avis}\nMatt's Couverture",
        ])->assertSessionHasNoErrors();
    }

    private function paidInvoice(Client $client): Invoice
    {
        $this->post(route('invoices.store'), [
            'client_id' => $client->id, 'due_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '900']],
        ]);
        $invoice = Invoice::query()->latest('id')->firstOrFail();
        $this->post(route('invoices.send', $invoice));
        $this->post(route('payments.store', $invoice), ['amount' => '900', 'paid_at' => today()->toDateString(), 'method' => 'virement']);

        return $invoice->fresh();
    }

    public function test_review_is_requested_by_email_two_days_after_payment_once_a_year(): void
    {
        $this->enable();
        $client = Client::factory()->create(['civility' => 'Mme', 'last_name' => 'Morel', 'email' => 'morel@example.com']);
        $invoice = $this->paidInvoice($client);
        $this->assertSame('paid', $invoice->status);

        $this->artisan('app:review-requests')->expectsOutputToContain('0 demande(s)');
        Mail::assertNothingSent();

        Carbon::setTestNow('2026-10-07 10:00');
        $this->artisan('app:review-requests')->expectsOutputToContain('1 demande(s) envoyée(s) par email');
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => $m->hasTo('morel@example.com')
            && $m->buttonUrl === self::GOOGLE && str_contains($m->text, 'Bonjour Madame Morel,') && ! str_contains($m->text, self::GOOGLE));
        $this->assertSame('sent', ReviewRequest::query()->sole()->status);

        // Nouveau chantier payé 2 mois plus tard : pas de nouvelle demande.
        Carbon::setTestNow('2026-12-07 10:00');
        $this->paidInvoice($client);
        Carbon::setTestNow('2026-12-10 10:00');
        $this->artisan('app:review-requests')->expectsOutputToContain('0 demande(s) envoyée(s) par email, 0');
        $this->assertSame(1, ReviewRequest::query()->count());
    }

    public function test_client_without_email_gets_a_manual_request_and_old_invoices_are_ignored(): void
    {
        $old = Client::factory()->create(['email' => 'ancien@example.com']);
        $this->paidInvoice($old); // Payé avant l'activation : jamais sollicité.

        Carbon::setTestNow('2026-10-06 09:00');
        $this->enable();
        $client = Client::factory()->create(['last_name' => 'Sansmail', 'email' => null, 'phone' => '06 11 22 33 44']);
        $this->paidInvoice($client);

        $push = Mockery::mock(PushService::class);
        $this->app->instance(PushService::class, $push);
        $push->shouldReceive('send')->once()->withArgs(fn ($title) => str_contains($title, 'Sansmail'));
        Carbon::setTestNow('2026-10-09 10:00');
        $this->artisan('app:review-requests')->assertSuccessful();
        Mail::assertNothingSent();

        $review = ReviewRequest::query()->sole();
        $this->get(route('reviews.index'))->assertOk()->assertSee('Sansmail');
        $this->get(route('reviews.show', $review))->assertOk()->assertSee(self::GOOGLE);
        $this->postJson(route('reviews.track', $review), ['channel' => 'whatsapp'])->assertOk();
        $this->assertSame(['sent', 'whatsapp'], [$review->fresh()->status, $review->fresh()->channel]);
    }

    public function test_manual_request_from_a_paid_invoice_and_settings_validation(): void
    {
        $this->put(route('settings.emails.reviews'), ['enabled' => '1', 'delay_days' => 2, 'review_subject' => 'x', 'review' => 'y'])->assertSessionHasErrors('google_url');
        $this->enable();

        $client = Client::factory()->create(['email' => 'c@example.com']);
        $invoice = $this->paidInvoice($client);
        $this->get(route('invoices.show', $invoice))->assertSee('Demander un avis Google');
        $this->post(route('reviews.store', $invoice))->assertRedirect(route('reviews.show', ReviewRequest::query()->sole()));
        $this->post(route('reviews.email', ReviewRequest::query()->sole()))->assertRedirect(route('reviews.index'));
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => $m->buttonUrl === self::GOOGLE);
    }
}

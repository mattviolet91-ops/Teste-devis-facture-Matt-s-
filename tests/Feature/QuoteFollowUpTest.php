<?php

namespace Tests\Feature;

use App\Mail\ClientMessage;
use App\Models\Client;
use App\Models\Quote;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class QuoteFollowUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 10:30');
        Mail::fake();
        $this->app->instance(PushService::class, Mockery::mock(PushService::class)->shouldIgnoreMissing());
        $this->actingAs($this->admin());
        $this->put(route('settings.emails'), ['username' => 'mv.entreprise91@gmail.com', 'password' => 'abcdabcdabcdabcd']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sentQuote(?string $email = 'cliente@example.com'): Quote
    {
        $client = Client::factory()->create(['civility' => 'Mme', 'last_name' => 'Roux', 'email' => $email]);
        $this->post(route('quotes.store'), [
            'client_id' => $client->id, 'title' => 'Démoussage', 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '900']],
        ]);
        $quote = Quote::query()->latest('id')->firstOrFail();
        $this->post(route('quotes.send', $quote));

        return $quote->fresh();
    }

    private function run7and15(): void
    {
        $this->put(route('settings.emails.quote-follow-ups'), ['quotes_auto' => '1', 'quotes_first_days' => 7, 'quotes_second_days' => 15])->assertSessionHasNoErrors();
    }

    public function test_quote_is_followed_up_after_7_then_15_days_then_stops(): void
    {
        $quote = $this->sentQuote();
        $this->run7and15();

        Carbon::setTestNow('2026-10-07 10:30');
        $this->artisan('app:quote-follow-ups')->expectsOutputToContain('0 devis relancé(s)');

        Carbon::setTestNow('2026-10-08 10:30');
        $this->artisan('app:quote-follow-ups')->expectsOutputToContain('1 devis relancé(s)');
        Mail::assertSent(ClientMessage::class, fn (ClientMessage $m) => $m->hasTo('cliente@example.com')
            && str_contains($m->mailSubject, 'Suite à notre devis n° '.$quote->number) && $m->buttonUrl === $quote->publicUrl() && $m->pdf === null);
        $this->artisan('app:quote-follow-ups')->expectsOutputToContain('0 devis relancé(s)');

        Carbon::setTestNow('2026-10-16 10:30');
        $this->artisan('app:quote-follow-ups')->expectsOutputToContain('1 devis relancé(s)');
        Carbon::setTestNow('2026-10-25 10:30');
        $this->artisan('app:quote-follow-ups')->expectsOutputToContain('0 devis relancé(s)');

        $this->assertSame(2, $quote->fresh()->follow_up_count);
        Mail::assertSentCount(2);
        $this->get(route('quotes.show', $quote))->assertSee('Relancé automatiquement 2 fois');
    }

    public function test_signed_refused_expired_or_emailless_quotes_are_never_followed_up(): void
    {
        $signed = $this->sentQuote();
        $refused = $this->sentQuote();
        $noEmail = $this->sentQuote(null);
        $expired = $this->sentQuote();
        $this->post(route('quotes.accept', $signed));
        $this->post(route('quotes.refuse', $refused), ['refusal_reason' => 'Trop cher']);
        $expired->forceFill(['valid_until' => '2026-10-05'])->save();
        $this->run7and15();

        Carbon::setTestNow('2026-10-09 10:30');
        $this->artisan('app:quote-follow-ups')->expectsOutputToContain('0 devis relancé(s)');
        Mail::assertNothingSent();
        $this->assertSame(0, $noEmail->fresh()->follow_up_count);
    }

    public function test_disabled_by_default_and_settings_validation(): void
    {
        $this->sentQuote();
        Carbon::setTestNow('2026-10-20 10:30');
        $this->artisan('app:quote-follow-ups')->expectsOutputToContain('désactivée');
        Mail::assertNothingSent();

        $this->get(route('settings.emails'))->assertSee('Relance des devis sans réponse')->assertSee('dont 1 avec une adresse email');
        $this->put(route('settings.emails.quote-follow-ups'), ['quotes_auto' => '1', 'quotes_first_days' => 10, 'quotes_second_days' => 10])
            ->assertSessionHasErrors('quotes_second_days');
    }
}

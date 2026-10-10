<?php

namespace Tests\Feature;

use App\Http\Middleware\RestoreExpiredForm;
use App\Models\Client;
use App\Models\Quote;
use App\Support\Money;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Enregistrement d'un devis : rien ne doit être perdu ni ignoré. */
class QuoteSavingTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Client::factory()->create(['last_name' => 'Martin']);
        $this->actingAs($this->admin());
    }

    private function form(string $title, array $extra = []): array
    {
        return array_replace([
            'client_id' => $this->client->id, 'title' => $title, 'validity_days' => 30,
            'lines' => [['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '500']],
        ], $extra);
    }

    public function test_same_form_id_with_different_content_is_saved_again(): void
    {
        $once = '0f0e0d0c-0b0a-4908-8706-050403020100';

        // Formulaire retrouvé par un retour arrière (même identifiant) puis modifié : nouveau devis.
        $this->post(route('quotes.store'), $this->form('Devis A') + ['_once' => $once])->assertSessionHasNoErrors();
        $this->post(route('quotes.store'), $this->form('Devis B') + ['_once' => $once])->assertSessionHasNoErrors();
        $this->assertSame(['Devis A', 'Devis B'], Quote::query()->orderBy('id')->pluck('title')->all());

        // Exactement le même envoi (double appui, réponse perdue) : toujours une seule fois.
        $this->post(route('quotes.store'), $this->form('Devis B') + ['_once' => $once])
            ->assertSessionHas('status', fn ($s) => str_starts_with($s, 'Déjà enregistré'));
        $this->assertSame(2, Quote::query()->count());
    }

    public function test_blank_line_left_by_mistake_does_not_block_saving(): void
    {
        $this->post(route('quotes.store'), $this->form('Toiture', ['lines' => [
            ['type' => 'item', 'title' => 'Démoussage', 'quantity' => '1', 'unit_price' => '500'],
            ['type' => 'item', 'title' => '', 'description' => '', 'quantity' => '1', 'unit_price' => '', 'discount_percent' => ''],
            ['type' => 'section', 'title' => ''],
        ]]))->assertSessionHasNoErrors();

        $quote = Quote::query()->with('lines')->sole();
        $this->assertSame(['Démoussage'], $quote->lines->pluck('title')->all());

        // Une ligne commencée mais incomplète reste signalée.
        $this->post(route('quotes.store'), $this->form('Toiture', ['lines' => [
            ['type' => 'item', 'title' => '', 'quantity' => '1', 'unit_price' => '80'],
        ]]))->assertSessionHasErrors(['lines.0.title' => 'Ligne 1 : la désignation est obligatoire.']);
    }

    public function test_prices_written_with_a_thousands_dot(): void
    {
        $this->assertSame(150000, Money::parse('1.500'));
        $this->assertSame(150050, Money::parse('1.500,50'));
        $this->assertSame(150, Money::parse('1.50'));
        $this->assertSame(1250, Money::parse('12.5'));
        $this->assertNull(Money::parse('1.5000'));

        $this->post(route('quotes.store'), $this->form('Velux', ['lines' => [
            ['type' => 'item', 'title' => 'Pose de velux', 'quantity' => '2', 'unit_price' => '1.500'],
        ]]))->assertSessionHasNoErrors();
        $this->assertSame(300000, Quote::query()->sole()->total_ht);
    }

    public function test_expired_page_keeps_the_quote_and_shows_it_again(): void
    {
        $request = Request::create(route('quotes.store'), 'POST', $this->form('Gardé', ['_token' => 'perime', '_once' => 'x']));
        $request->headers->set('referer', route('quotes.create'));
        $request->setLaravelSession($session = $this->app['session.store']);

        $response = $this->app->make(ExceptionHandler::class)->render($request, new HttpException(419));

        $this->assertSame(route('quotes.create'), $response->headers->get('Location'));
        $saved = $session->get(RestoreExpiredForm::KEY);
        $this->assertSame(route('quotes.create'), $saved['url']);
        $this->assertArrayNotHasKey('_token', $saved['input']);
        $this->assertSame(0, Quote::query()->count());

        // De retour sur la page (après reconnexion si besoin) : le formulaire est rempli.
        $this->withSession([RestoreExpiredForm::KEY => $saved])->get(route('quotes.create'))
            ->assertOk()->assertSee('rien n&#039;est perdu', false)->assertSee('value="Gardé"', false)->assertSee('value="Démoussage"', false);
        $this->get(route('quotes.create'))->assertDontSee('value="Gardé"', false);
    }

    public function test_expired_login_page_just_asks_to_log_in_again(): void
    {
        auth()->logout();
        $request = Request::create('/connexion', 'POST', ['email' => 'a@b.c', 'password' => 'secret', '_token' => 'perime']);
        $request->headers->set('referer', route('login'));
        $request->setLaravelSession($session = $this->app['session.store']);

        $response = $this->app->make(ExceptionHandler::class)->render($request, new HttpException(419));

        $this->assertSame(route('login'), $response->headers->get('Location'));
        $this->assertNull($session->get(RestoreExpiredForm::KEY));
    }
}

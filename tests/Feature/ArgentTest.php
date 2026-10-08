<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\MoneyAccount;
use App\Models\MoneyCategory;
use App\Models\MoneyGoal;
use App\Models\MoneyRecurring;
use App\Models\MoneyRule;
use App\Models\MoneyTransaction;
use App\Models\MoneyWeeklyReport;
use App\Models\Payment;
use App\Models\User;
use App\Services\MoneyImportService;
use App\Services\MoneySyncService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ArgentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00');
        Mail::fake();
        $this->owner = $this->admin();
        $this->actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Facture de 1 000 € envoyée, payée en partie (400 €) le 10/10, et un frais de 120 € le 12/10. */
    private function quoteActivity(): Invoice
    {
        $client = Client::factory()->create(['last_name' => 'Durand', 'first_name' => 'Paul']);
        $this->post(route('invoices.store'), [
            'client_id' => $client->id, 'due_days' => 0,
            'lines' => [['type' => 'item', 'title' => 'Réparation toiture', 'quantity' => '1', 'unit_price' => '1000']],
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::query()->latest('id')->firstOrFail();
        $this->post(route('invoices.send', $invoice));
        $this->post(route('payments.store', $invoice), ['amount' => '400', 'paid_at' => '2026-10-10', 'method' => 'virement'])->assertSessionHasNoErrors();
        $this->post(route('expenses.store.any'), ['job' => 'general', 'expense_label' => 'Tuiles', 'expense_amount' => '120', 'expense_date' => '2026-10-12', 'category' => 'materiaux'])
            ->assertSessionHasNoErrors();

        return $invoice->fresh();
    }

    private function setUpMoney(string $code = '482913'): void
    {
        $this->post(route('money.setup.store'), ['code' => $code, 'code_confirmation' => $code, 'perso_balance' => '1 500,00', 'pro_balance' => '-200'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('money.dashboard'));
    }

    private function account(string $scope): MoneyAccount
    {
        return MoneyAccount::query()->where('scope', $scope)->firstOrFail();
    }

    public function test_first_opening_asks_for_a_code_and_brings_in_quote_payments_and_expenses(): void
    {
        $invoice = $this->quoteActivity();

        $this->get(route('money.dashboard'))->assertRedirect(route('money.setup'));
        $this->get(route('money.setup'))->assertOk()->assertSee('Choisissez un code Argent');
        $this->post(route('money.setup.store'), ['code' => '12', 'code_confirmation' => '12'])->assertSessionHasErrors('code');
        $this->post(route('money.setup.store'), ['code' => '1234', 'code_confirmation' => '9999'])->assertSessionHasErrors('code');
        $this->setUpMoney();

        $this->assertSame(150000, $this->account('perso')->balance());
        // Solde pro de départ (−200 €) au 14/10 : le paiement du 10 et le frais du 12 comptent dans les bilans, pas dans ce solde.
        $this->assertSame(-20000, $this->account('pro')->balance());

        $payment = MoneyTransaction::query()->where('source_ref', 'payment:'.Payment::query()->sole()->id)->sole();
        $this->assertSame(40000, $payment->amount);
        $this->assertSame('income', $payment->kind);
        $this->assertSame('devis_payment', $payment->category->system_key);
        $this->assertStringContainsString($invoice->number, $payment->label);
        $expense = MoneyTransaction::query()->where('source_ref', 'expense:'.Expense::query()->sole()->id)->sole();
        $this->assertSame(-12000, $expense->amount);
        $this->assertSame('devis_materiaux', $expense->category->system_key);

        $this->get(route('money.dashboard', ['vue' => 'pro']))->assertOk()
            ->assertSee(Money::format(40000))->assertSee(Money::format(12000))->assertSee('Logiciel de devis')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_only_the_owner_can_open_it_and_never_a_commercial(): void
    {
        $other = $this->admin();
        $commercial = User::factory()->create(['role' => 'commercial']);

        $this->actingAs($commercial)->get(route('money.dashboard'))->assertForbidden();
        $this->actingAs($commercial)->get(route('money.setup'))->assertForbidden();
        $this->actingAs($commercial)->get(route('dashboard'))->assertOk()->assertDontSee(route('money.dashboard'));

        $this->actingAs($this->owner);
        $this->setUpMoney();
        $this->get(route('dashboard'))->assertSee(route('money.dashboard'));

        // Un autre gérant ne voit ni le lien ni les pages, même pas l'écran du code.
        $this->actingAs($other)->get(route('dashboard'))->assertOk()->assertDontSee(route('money.dashboard'));
        $this->actingAs($other)->get(route('money.dashboard'))->assertForbidden();
        $this->actingAs($other)->get(route('money.unlock'))->assertForbidden();
        $this->actingAs($other)->post(route('money.setup.store'), ['code' => '1111', 'code_confirmation' => '1111'])->assertForbidden();
        $this->actingAs($other)->get(route('money.export'))->assertForbidden();
    }

    public function test_code_is_asked_again_after_locking_or_inactivity_and_blocks_after_five_wrong_codes(): void
    {
        $this->setUpMoney('482913');
        $this->get(route('money.transactions.index'))->assertOk();

        $this->post(route('money.lock'))->assertRedirect(route('dashboard'));
        $this->get(route('money.transactions.index'))->assertRedirect(route('money.unlock'));
        $this->post(route('money.unlock.store'), ['code' => '482913'])->assertRedirect(route('money.transactions.index'));

        // 15 minutes sans rien ouvrir : reverrouillé.
        Carbon::setTestNow(now()->addMinutes(16));
        $this->get(route('money.dashboard'))->assertRedirect(route('money.unlock'));

        for ($i = 1; $i <= 4; $i++) {
            $this->post(route('money.unlock.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->post(route('money.unlock.store'), ['code' => '000000'])->assertSessionHasErrors(['code' => 'Code faux. Espace bloqué 15 minutes.']);
        $this->assertDatabaseHas('activity_log', ['action' => 'argent.blocked']);
        // Même le bon code est refusé pendant le blocage.
        $this->post(route('money.unlock.store'), ['code' => '482913'])->assertSessionHasErrors('code');
        $this->get(route('money.dashboard'))->assertRedirect(route('money.unlock'));

        Carbon::setTestNow(now()->addMinutes(16));
        $this->post(route('money.unlock.store'), ['code' => '482913'])->assertRedirect(route('money.dashboard'));
        $this->get(route('money.dashboard'))->assertOk();
    }

    public function test_forgotten_code_is_replaced_with_the_account_password_and_old_code_stops_working(): void
    {
        $this->owner->update(['password' => 'MotDePasse-2026!']);
        $this->setUpMoney('482913');
        $this->post(route('money.lock'));

        $this->post(route('money.forgot.store'), ['password' => 'faux', 'code' => '5555', 'code_confirmation' => '5555'])->assertSessionHasErrors('password');
        $this->post(route('money.forgot.store'), ['password' => 'MotDePasse-2026!', 'code' => '5555', 'code_confirmation' => '5555'])
            ->assertRedirect(route('money.dashboard'));
        $this->post(route('money.lock'));
        $this->post(route('money.unlock.store'), ['code' => '482913'])->assertSessionHasErrors('code');
        $this->post(route('money.unlock.store'), ['code' => '5555'])->assertRedirect(route('money.dashboard'));
    }

    public function test_quick_add_expense_income_and_transfer(): void
    {
        $this->setUpMoney();
        $perso = $this->account('perso');
        $pro = $this->account('pro');
        $courses = MoneyCategory::query()->where('name', 'Courses')->sole();
        $salary = MoneyCategory::query()->where('name', 'Salaire / rémunération')->sole();

        $this->from(route('money.dashboard'))->post(route('money.transactions.store'), [
            'type' => 'expense', 'amount' => '62,40', 'account_id' => $perso->id, 'category_id' => $courses->id, 'label' => 'Leclerc', 'occurred_on' => '2026-10-14',
        ])->assertRedirect(route('money.dashboard'))->assertSessionHas('status', 'Dépense ajoutée.');
        $this->post(route('money.transactions.store'), [
            'type' => 'income', 'amount' => '2000', 'account_id' => $perso->id, 'category_id' => $salary->id, 'occurred_on' => '2026-10-01',
        ])->assertSessionHasNoErrors();
        $this->post(route('money.transactions.store'), [
            'type' => 'transfer', 'amount' => '300', 'account_id' => $pro->id, 'to_account_id' => $perso->id, 'occurred_on' => '2026-10-14',
        ])->assertSessionHasNoErrors();
        // Catégorie de revenu sur une dépense : refusée. Montant nul : refusé.
        $this->post(route('money.transactions.store'), ['type' => 'expense', 'amount' => '5', 'account_id' => $perso->id, 'category_id' => $salary->id, 'occurred_on' => '2026-10-14'])
            ->assertSessionHasErrors('category_id');
        $this->post(route('money.transactions.store'), ['type' => 'expense', 'amount' => '0', 'account_id' => $perso->id, 'occurred_on' => '2026-10-14'])
            ->assertSessionHasErrors('amount');

        // Le salaire du 1er est avant la date du solde de départ (14/10) : déjà compris dedans.
        $this->assertSame(150000 - 6240 + 30000, $perso->balance());
        $this->assertSame(-20000 - 30000, $pro->balance());
        $this->assertSame('Salaire / rémunération', MoneyTransaction::query()->where('amount', 200000)->sole()->label);

        // Le virement ne compte ni comme gagné ni comme dépensé.
        $response = $this->get(route('money.dashboard', ['vue' => 'perso', 'periode' => 'mois']))->assertOk();
        $response->assertViewHas('totals', ['income' => 200000, 'expense' => 6240, 'net' => 193760]);

        $this->get(route('money.transactions.index', ['vue' => 'all', 'q' => 'Leclerc']))->assertOk()->assertSee('Leclerc')->assertDontSee('Virement vers');

        // Supprimer un côté du virement supprime les deux.
        $transfer = MoneyTransaction::query()->where('kind', 'transfer')->where('amount', '<', 0)->sole();
        $this->delete(route('money.transactions.destroy', $transfer))->assertRedirect(route('money.transactions.index'));
        $this->assertSame(0, MoneyTransaction::query()->where('kind', 'transfer')->count());
    }

    public function test_weekly_sync_never_duplicates_follows_deletions_and_keeps_manual_category(): void
    {
        $this->quoteActivity();
        $this->setUpMoney();
        $sync = app(MoneySyncService::class);
        $this->assertSame(['added' => 0, 'updated' => 0, 'removed' => 0], $sync->run());
        $this->assertSame(2, MoneyTransaction::query()->where('source', 'devis')->count());

        $line = MoneyTransaction::query()->where('source_ref', 'like', 'payment:%')->sole();
        $other = MoneyCategory::query()->where('name', 'Autres recettes pro')->sole();
        $this->put(route('money.transactions.update', $line), ['category_id' => $other->id, 'label' => 'Chantier Durand'])->assertSessionHasNoErrors();
        // Une ligne venant des devis ne se supprime pas ici.
        $this->delete(route('money.transactions.destroy', $line))->assertSessionHasErrors('transaction');

        // Suppression du paiement dans les devis : la ligne disparaît à la mise à jour suivante.
        $this->delete(route('payments.destroy', Payment::query()->sole()));
        $this->post(route('money.sync'))->assertSessionHas('status');
        $this->assertSame(0, MoneyTransaction::query()->where('source_ref', 'like', 'payment:%')->count());

        $this->post(route('payments.store', Invoice::query()->sole()), ['amount' => '250', 'paid_at' => '2026-10-13', 'method' => 'especes'])->assertSessionHasNoErrors();
        $cash = MoneyAccount::query()->create(['name' => 'Caisse', 'kind' => 'especes', 'scope' => 'pro', 'opening_on' => '2026-10-01']);
        $this->put(route('money.settings.update'), ['lock_minutes' => 15, 'sync_account_id' => $this->account('pro')->id, 'cash_account_id' => $cash->id, 'weekly_push' => 1])
            ->assertSessionHasNoErrors();
        $sync->run();
        $line = MoneyTransaction::query()->where('source_ref', 'like', 'payment:%')->sole();
        $this->assertSame($cash->id, $line->account_id);
        $this->assertSame(25000, $cash->balance());
        $this->get(route('money.dashboard', ['vue' => 'pro']))->assertOk()->assertSee(Money::format(25000))->assertDontSee('URSSAF, impôts ·');
    }

    public function test_fixed_expenses_are_written_on_their_due_dates_and_forecast(): void
    {
        $this->setUpMoney();
        $perso = $this->account('perso');
        $rent = MoneyCategory::query()->where('name', 'Logement (loyer, crédit)')->sole();

        $this->post(route('money.recurrings.store'), [
            'label' => 'Loyer', 'type' => 'expense', 'amount' => '750', 'account_id' => $perso->id, 'category_id' => $rent->id,
            'frequency' => 'mensuel', 'next_on' => '2026-09-05',
        ])->assertSessionHasNoErrors();
        // 5 septembre et 5 octobre déjà passés : notés tout de suite, prochain le 5 novembre.
        $this->assertSame(2, MoneyTransaction::query()->where('source', 'recurring')->count());
        $this->assertSame('2026-11-05', MoneyRecurring::query()->sole()->next_on->toDateString());

        $this->post(route('money.recurrings.store'), [
            'label' => 'Netflix', 'type' => 'expense', 'amount' => '13,49', 'account_id' => $perso->id, 'frequency' => 'mensuel', 'next_on' => '2026-10-20',
        ])->assertSessionHasNoErrors();
        $this->get(route('money.recurrings.index'))->assertOk()->assertSee('Netflix')->assertSee(Money::format(75000 + 1349));
        $this->get(route('money.dashboard'))->assertOk()->assertSee('Netflix');

        Carbon::setTestNow('2026-10-20 07:00');
        $this->artisan('app:argent-jour')->assertSuccessful();
        $this->assertSame(1, MoneyTransaction::query()->where('label', 'Netflix')->count());
    }

    public function test_bank_statement_import_skips_duplicates_and_learns_categories(): void
    {
        $this->quoteActivity();
        $this->setUpMoney();
        $pro = $this->account('pro');
        $courses = MoneyCategory::query()->where('name', 'Courses')->sole();
        $csv = mb_convert_encoding(implode("\r\n", [
            'Téléchargement du 14/10/2026;;;;',
            'Compte courant n° 123;;;;',
            '',
            'Date;Libellé;Débit euros;Crédit euros;',
            '13/10/2026;CB CARREFOUR MARKET 12/10;45,20;;',
            '10/10/2026;VIR SEPA RECU DE DURAND PAUL;;400,00;',
            '11/10/2026;PRLV SEPA EDF CLIENTS PARTICULIERS;"1 234,56";;',
        ]), 'Windows-1252', 'UTF-8');

        $this->post(route('money.import.preview'), ['account_id' => $pro->id, 'file' => UploadedFile::fake()->createWithContent('releve.csv', $csv)])
            ->assertRedirect(route('money.import.show'));
        $response = $this->get(route('money.import.show'))->assertOk()->assertSee('CB CARREFOUR MARKET 12/10')->assertSee('Déjà notée ?');
        $rows = $response->viewData('rows');
        $this->assertSame([-4520, 40000, -123456], array_column($rows, 'amount'));
        // Le virement du client ressemble au paiement déjà venu des devis : décoché.
        $this->assertSame(['new', 'similar', 'new'], array_column($rows, 'status'));

        $this->post(route('money.import.store'), ['import' => [0 => 1, 2 => 1], 'category' => [0 => $courses->id]])
            ->assertRedirect(route('money.transactions.index', ['compte' => $pro->id, 'periode' => 'tout']));
        $this->assertSame(2, MoneyTransaction::query()->where('source', 'import')->count());
        $this->assertSame($courses->id, MoneyRule::query()->where('keyword', 'carrefour market')->value('category_id'));

        // Même relevé une 2e fois : rien de neuf ; la catégorie apprise est proposée.
        $this->post(route('money.import.preview'), ['account_id' => $pro->id, 'file' => UploadedFile::fake()->createWithContent('releve.csv', $csv)]);
        $rows = $this->get(route('money.import.show'))->viewData('rows');
        $this->assertSame(['known', 'similar', 'known'], array_column($rows, 'status'));
        $this->assertSame($courses->id, $rows[0]['category_id']);

        $this->post(route('money.import.preview'), ['account_id' => $pro->id, 'file' => UploadedFile::fake()->createWithContent('vide.csv', "a;b\n1;2")])
            ->assertSessionHasErrors('file');
    }

    public function test_ofx_statement_and_labels_are_read(): void
    {
        $ofx = "OFXHEADER:100\nDATA:OFXSGML\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST>\n"
            ."<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261003120000<TRNAMT>-12.50<FITID>A1<NAME>BOULANGERIE<MEMO>CB 02/10\n"
            ."<STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20261005<TRNAMT>1500,00<FITID>A2<NAME>SALAIRE\n"
            .'</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>';
        $rows = app(MoneyImportService::class)->parse($ofx);

        $this->assertSame([
            ['date' => '2026-10-03', 'label' => 'BOULANGERIE CB 02/10', 'amount' => -1250, 'fitid' => 'A1'],
            ['date' => '2026-10-05', 'label' => 'SALAIRE', 'amount' => 150000, 'fitid' => 'A2'],
        ], $rows);
        $this->assertSame('carrefour market', app(MoneyImportService::class)->keyword('CB CARREFOUR MARKET 12/10 X1234'));
        $this->assertSame('edf clients', app(MoneyImportService::class)->keyword('PRLV SEPA EDF CLIENTS PARTICULIERS'));
    }

    public function test_goals_budgets_and_accounts(): void
    {
        $this->setUpMoney();
        $perso = $this->account('perso');
        $courses = MoneyCategory::query()->where('name', 'Courses')->sole();

        $this->post(route('money.goals.store'), ['name' => 'Vacances', 'kind' => 'epargne', 'target' => '1200', 'deadline' => '2027-04-14', 'saved' => '200'])
            ->assertSessionHasNoErrors();
        $goal = MoneyGoal::query()->sole();
        $this->post(route('money.goals.contribute', $goal), ['contribution' => '1000'])->assertSessionHas('status', 'Objectif « Vacances » atteint, bravo !');
        $this->assertNotNull($goal->fresh()->achieved_at);

        $this->post(route('money.goals.store'), ['name' => 'Pas plus de 2 000 €', 'kind' => 'depenses', 'target' => '2000', 'period' => 'mois', 'scope' => 'perso'])->assertSessionHasNoErrors();
        $this->put(route('money.categories.update', $courses), ['monthly_budget' => '400'])->assertSessionHasNoErrors();
        $this->post(route('money.transactions.store'), ['type' => 'expense', 'amount' => '450', 'account_id' => $perso->id, 'category_id' => $courses->id, 'occurred_on' => '2026-10-02']);

        $this->get(route('money.categories.index'))->assertOk()->assertSee('113 %');
        $this->get(route('money.goals.index'))->assertOk()->assertSee('Vacances')->assertSee('Encore '.Money::format(155000).' possibles ce mois-ci');

        $this->post(route('money.accounts.store'), ['name' => 'Livret A', 'kind' => 'epargne', 'scope' => 'perso', 'opening_balance' => '5 000', 'opening_on' => '2026-10-01', 'color' => '#00AA88'])
            ->assertSessionHasNoErrors();
        $livret = MoneyAccount::query()->where('name', 'Livret A')->sole();
        $this->get(route('money.accounts.show', $livret))->assertOk()->assertSee(Money::format(500000));
        $this->delete(route('money.accounts.destroy', $livret))->assertRedirect(route('money.accounts.index'));
        $this->assertModelMissing($livret);
        // Compte avec des mouvements : archivé, pas supprimé.
        $this->delete(route('money.accounts.destroy', $perso));
        $this->assertNotNull($perso->fresh()->archived_at);
    }

    public function test_monday_update_makes_the_weekly_report_and_every_page_opens(): void
    {
        $this->quoteActivity();
        $this->setUpMoney();
        $this->post(route('money.transactions.store'), ['type' => 'expense', 'amount' => '80', 'account_id' => $this->account('perso')->id, 'label' => 'Essence', 'occurred_on' => '2026-10-13']);

        Carbon::setTestNow('2026-10-19 07:00');
        $this->artisan('app:argent-semaine')->assertSuccessful();
        $report = MoneyWeeklyReport::query()->sole();
        $this->assertSame('2026-10-12', $report->week_start->toDateString());
        $this->assertSame(['income' => 0, 'expense' => 20000, 'net' => -20000], $report->data['all']);
        // Le paiement du samedi 10 était la semaine d'avant ; le frais du 12 est dans celle-ci.
        $this->assertSame(0, $report->data['quotes']['collected']);
        $this->assertSame(12000, $report->data['quotes']['spent']);

        $this->post(route('money.unlock.store'), ['code' => '482913']);
        foreach ([
            route('money.dashboard'), route('money.dashboard', ['periode' => 'annee', 'vue' => 'pro']), route('money.transactions.index', ['periode' => 'tout']),
            route('money.accounts.index'), route('money.categories.index'), route('money.goals.index'), route('money.recurrings.index'),
            route('money.reports.index'), route('money.reports.show', $report), route('money.settings'), route('money.import.create'),
            route('money.transactions.edit', MoneyTransaction::query()->first()), route('guide'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('money.reports.show', $report))->assertSee('Semaine du 12 octobre au 18 octobre 2026');

        $csv = $this->get(route('money.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Essence', $csv);
        $this->assertStringContainsString('-80,00', $csv);
    }
}

<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Models\MoneyAccount;
use App\Models\MoneyTransaction;
use App\Services\ActivityLogger;
use App\Services\MoneyLockService;
use App\Services\MoneySyncService;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Réglages de l'espace Argent : code, verrouillage, lien avec les devis, notifications, export. */
class SettingsController extends Controller
{
    public function __construct(private readonly Settings $settings, private readonly MoneyLockService $lock) {}

    public function edit(MoneySyncService $sync): View
    {
        $last = $this->settings->get('argent.last_sync_at');

        return view('money.settings', [
            'accounts' => MoneyAccount::query()->active()->ordered()->get(),
            'syncAccount' => $sync->account(),
            'cashAccount' => $sync->cashAccount(),
            'lastSync' => $last ? Carbon::parse($last) : null,
            'lastResult' => (array) $this->settings->get('argent.last_sync', []),
            'lockMinutes' => $this->lock->lockMinutes(),
            'weeklyPush' => (bool) $this->settings->get('argent.weekly_push', true),
            'pushAmounts' => (bool) $this->settings->get('argent.push_amounts', false),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'lock_minutes' => ['required', 'integer', Rule::in(array_keys(MoneyLockService::DELAYS))],
            'sync_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')],
            'cash_account_id' => ['nullable', 'integer', Rule::exists('money_accounts', 'id')],
        ], [], ['sync_account_id' => 'compte']);

        $this->settings->set([
            'argent.lock_minutes' => (int) $request->input('lock_minutes'),
            'argent.sync_account_id' => $request->integer('sync_account_id') ?: null,
            'argent.cash_account_id' => $request->integer('cash_account_id') ?: null,
            'argent.weekly_push' => $request->boolean('weekly_push'),
            'argent.push_amounts' => $request->boolean('push_amounts'),
        ]);
        $this->lock->unlock($request);

        return redirect()->route('money.settings')->with('status', 'Réglages enregistrés.');
    }

    public function updateCode(Request $request): RedirectResponse
    {
        $request->validate([
            'current_code' => ['required', 'string'],
            'code' => ['required', 'string', 'regex:/^\d{4,8}$/', 'confirmed'],
        ], [
            'current_code.required' => 'Tapez le code actuel.',
            'code.regex' => 'Le code doit faire de 4 à 8 chiffres.',
            'code.confirmed' => 'Les deux codes ne sont pas identiques.',
        ]);
        if (! $this->lock->checkCode((string) $request->input('current_code'))) {
            $this->lock->failed($request);
            throw ValidationException::withMessages(['current_code' => 'Code actuel incorrect.']);
        }

        $this->lock->setCode($request->user(), (string) $request->input('code'));
        $this->lock->unlock($request);
        ActivityLogger::log('argent.code', 'Code Argent changé');

        return redirect()->route('money.settings')->with('status', 'Nouveau code Argent enregistré.');
    }

    /** « Mettre à jour maintenant » : paiements et frais des devis, dépenses fixes. */
    public function sync(Request $request, MoneySyncService $sync): RedirectResponse
    {
        $result = $sync->run();
        $fixed = $sync->runRecurring();
        if ($result === null) {
            return back()->withErrors(['sync' => 'Choisissez d\'abord le compte qui reçoit les paiements des devis (Réglages Argent).']);
        }

        return back()->with('status', 'À jour : '.$result['added'].' ajouté(s), '.$result['updated'].' modifié(s), '.$result['removed'].' retiré(s)'
            .($fixed ? ', '.$fixed.' dépense(s) fixe(s)' : '').'.');
    }

    /** Tous les mouvements en CSV (ouvrable dans Excel) : une copie à garder. */
    public function export(): StreamedResponse
    {
        ActivityLogger::log('argent.export', 'Export des mouvements de l\'espace Argent');

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Compte', 'Perso/Pro', 'Catégorie', 'Libellé', 'Montant', 'Type', 'Origine', 'Note'], ';', '"', '');
            foreach (MoneyTransaction::query()->with(['account', 'category'])->orderBy('occurred_on')->orderBy('id')->lazy(500) as $t) {
                fputcsv($out, [
                    $t->occurred_on->format('d/m/Y'),
                    $t->account?->name,
                    $t->account?->scopeLabel(),
                    $t->category?->name,
                    $t->label,
                    number_format($t->amount / 100, 2, ',', ''),
                    match ($t->kind) {
                        'transfer' => 'Virement', 'income' => 'Entrée', default => 'Sortie'
                    },
                    MoneyTransaction::SOURCES[$t->source] ?? $t->source,
                    $t->notes,
                ], ';', '"', '');
            }
            fclose($out);
        }, 'argent-'.today()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}

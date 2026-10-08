<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\MoneyAccount;
use App\Models\MoneyCategory;
use App\Models\MoneyRecurring;
use App\Models\MoneyTransaction;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lien avec le logiciel de devis : chaque paiement reçu devient une entrée et
 * chaque frais une sortie sur le compte pro choisi. Relancer la mise à jour ne crée
 * jamais de doublon ; un paiement supprimé (ou une facture annulée) disparaît.
 * La catégorie et le libellé changés à la main sont gardés.
 */
class MoneySyncService
{
    public function __construct(private readonly Settings $settings) {}

    /** Compte qui reçoit les paiements et les frais (null : lien désactivé). */
    public function account(): ?MoneyAccount
    {
        $id = (int) $this->settings->get('argent.sync_account_id', 0);

        return $id ? MoneyAccount::query()->find($id) : null;
    }

    /** Compte facultatif pour les paiements en espèces. */
    public function cashAccount(): ?MoneyAccount
    {
        $id = (int) $this->settings->get('argent.cash_account_id', 0);

        return $id ? MoneyAccount::query()->find($id) : null;
    }

    /** @return array{added: int, updated: int, removed: int}|null */
    public function run(): ?array
    {
        $account = $this->account();
        if (! $account) {
            return null;
        }
        $cash = $this->cashAccount();
        $result = ['added' => 0, 'updated' => 0, 'removed' => 0];
        $seen = [];

        DB::transaction(function () use ($account, $cash, &$result, &$seen) {
            $income = MoneyCategory::system('devis_payment');
            foreach (Payment::query()->counted()->with(['client', 'invoice'])->lazyById(200) as $payment) {
                $ref = 'payment:'.$payment->id;
                $seen[$ref] = true;
                $this->upsert($ref, [
                    'account_id' => ($cash && $payment->method === 'especes') ? $cash->id : $account->id,
                    'occurred_on' => $payment->paid_at->toDateString(),
                    'amount' => $payment->amount,
                ], [
                    'category_id' => $income?->id,
                    'label' => mb_substr('Paiement '.($payment->client?->displayName() ?? 'client').($payment->invoice?->number ? ' · facture '.$payment->invoice->number : ''), 0, 160),
                ], $result);
            }

            $categories = MoneyCategory::query()->whereIn('system_key', MoneyCategory::EXPENSE_KEYS)->pluck('id', 'system_key');
            foreach (Expense::query()->lazyById(200) as $expense) {
                $ref = 'expense:'.$expense->id;
                $seen[$ref] = true;
                $key = MoneyCategory::EXPENSE_KEYS[$expense->category] ?? 'devis_autre';
                $this->upsert($ref, [
                    'account_id' => $account->id,
                    'occurred_on' => $expense->spent_on->toDateString(),
                    'amount' => -$expense->amount_ttc,
                ], [
                    'category_id' => $categories[$key] ?? null,
                    'label' => mb_substr($expense->label.($expense->supplier ? ' · '.$expense->supplier : ''), 0, 160),
                ], $result);
            }

            $gone = MoneyTransaction::query()->where('source', 'devis')->pluck('source_ref', 'id')
                ->reject(fn ($ref) => isset($seen[$ref]))->keys();
            foreach ($gone->chunk(500) as $ids) {
                $result['removed'] += MoneyTransaction::query()->whereIn('id', $ids)->delete();
            }
        });

        $this->settings->set(['argent.last_sync_at' => now()->toIso8601String(), 'argent.last_sync' => $result]);

        return $result;
    }

    /**
     * Dépenses et revenus fixes arrivés à échéance : ajoutés aux mouvements (rattrape les jours manqués).
     */
    public function runRecurring(?Carbon $today = null): int
    {
        $today ??= today();
        $created = 0;
        foreach (MoneyRecurring::query()->where('active', true)->whereDate('next_on', '<=', $today)->get() as $recurring) {
            $date = $recurring->next_on->copy();
            for ($i = 0; $i < 60 && $date->lte($today); $i++) {
                MoneyTransaction::query()->create([
                    'account_id' => $recurring->account_id,
                    'occurred_on' => $date->toDateString(),
                    'amount' => $recurring->amount,
                    'kind' => MoneyTransaction::kindFor($recurring->amount),
                    'category_id' => $recurring->category_id,
                    'label' => $recurring->label,
                    'source' => 'recurring',
                    'recurring_id' => $recurring->id,
                ]);
                $created++;
                $date = $recurring->nextAfter($date);
            }
            $recurring->update(['next_on' => $date->toDateString()]);
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $values  toujours à jour (compte, date, montant)
     * @param  array<string, mixed>  $initial  seulement à la création (catégorie, libellé)
     * @param  array{added: int, updated: int, removed: int}  $result
     */
    private function upsert(string $ref, array $values, array $initial, array &$result): void
    {
        $values['kind'] = MoneyTransaction::kindFor((int) $values['amount']);
        $transaction = MoneyTransaction::query()->where('source_ref', $ref)->first();

        if (! $transaction) {
            MoneyTransaction::query()->create($values + $initial + ['source' => 'devis', 'source_ref' => $ref]);
            $result['added']++;

            return;
        }

        $transaction->fill($values);
        if ($transaction->isDirty()) {
            $transaction->save();
            $result['updated']++;
        }
    }
}

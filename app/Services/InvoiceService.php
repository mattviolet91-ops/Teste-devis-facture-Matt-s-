<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Quote;
use App\Models\TextTemplate;
use App\Services\Concerns\HandlesDocumentLines;
use App\Support\Money;
use App\Support\Percent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Cycle de vie des factures : brouillon, création depuis un devis (acompte,
 * situation, solde, facture complète), envoi (numérotation), annulation par
 * avoir et correction (avoir + nouvelle facture).
 */
class InvoiceService
{
    use HandlesDocumentLines;

    public function __construct(
        private readonly DocumentCalculator $calculator,
        private readonly NumberGenerator $numbers,
        private readonly Settings $settings,
    ) {}

    /** Nouvelle facture vierge avec les valeurs par défaut. */
    public function blank(?int $clientId = null, ?int $worksiteId = null): Invoice
    {
        $invoice = new Invoice([
            'client_id' => $clientId,
            'worksite_id' => $worksiteId,
            'due_days' => (int) $this->settings->get('documents.invoice_due_days', 0),
            'payment_terms' => TextTemplate::query()->where('label', 'Paiement à réception')->value('body'),
        ]);
        $invoice->kind = 'standard';
        $invoice->status = 'draft';
        $invoice->vat_regime = $this->settings->get('vat.regime');
        $invoice->show_bank = (bool) $this->settings->get('bank.show_by_default');

        return $invoice;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function saveDraft(Invoice $invoice, array $attributes, array $lines): Invoice
    {
        return DB::transaction(function () use ($invoice, $attributes, $lines) {
            $invoice->fill($attributes);
            $invoice->kind ??= 'standard';
            $invoice->status ??= 'draft';
            $invoice->vat_regime ??= $this->settings->get('vat.regime');
            $invoice->created_by ??= auth()->id();
            $invoice->save();
            $this->replaceLines($invoice, $lines);

            return $this->recalculate($invoice);
        });
    }

    /**
     * Brouillon de facture établi à partir d'un devis accepté.
     *
     * - standard : toutes les lignes du devis ;
     * - deposit / progress : un pourcentage du devis (une ligne par taux de TVA) ;
     * - final : les lignes du devis moins les acomptes et situations déjà facturés.
     */
    public function createFromQuote(Quote $quote, string $kind, ?int $percent = null): Invoice
    {
        if (! in_array($kind, ['standard', 'deposit', 'progress', 'final'], true)) {
            throw new InvalidArgumentException("Type de facture inconnu : $kind");
        }

        return DB::transaction(function () use ($quote, $kind, $percent) {
            $quote->loadMissing('lines');

            $invoice = $this->blank($quote->client_id, $quote->worksite_id);
            $invoice->fill($quote->only(['title', 'vat_regime', 'show_bank', 'notes', 'internal_notes']));
            $invoice->quote_id = $quote->id;
            $invoice->kind = $kind;
            $invoice->created_by = auth()->id();

            if (in_array($kind, ['deposit', 'progress'], true)) {
                $invoice->percent = $percent;
                $invoice->save();
                $this->replaceLines($invoice, $this->percentLines($quote, $kind, (int) $percent));
            } else {
                $lines = $quote->lines->map(fn ($line) => $line->only($line->getFillable()))->all();
                if ($kind === 'final') {
                    // La remise globale du devis devient une ligne : elle ne doit
                    // pas s'appliquer aux déductions d'acomptes.
                    $lines = array_merge($lines, $this->discountLines($quote), $this->deductionLines($quote));
                } else {
                    $invoice->fill($quote->only(['discount_type', 'discount_value']));
                }
                $invoice->save();
                $this->replaceLines($invoice, $lines);
            }

            ActivityLogger::log('invoice.created', Invoice::KINDS[$kind]." préparée depuis le devis {$quote->number}", $invoice);

            return $this->recalculate($invoice);
        });
    }

    /** Envoi : attribue le numéro de facture et fixe les dates. */
    public function send(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice->number ??= $this->numbers->next($invoice->isCredit() ? 'credit_note' : 'invoice');
            $invoice->public_token ??= Str::random(48);
            $invoice->status = 'sent';
            $invoice->sent_at = now();
            $invoice->issue_date = today();
            $invoice->due_date = today()->addDays($invoice->due_days);
            $invoice->save();

            ActivityLogger::log('invoice.sent', "{$invoice->fullTitle()} envoyée", $invoice);
            app(PdfService::class)->freeze($invoice);

            // Facture corrigée : les règlements reçus sur la facture d'origine la suivent.
            if ($invoice->corrects) {
                app(PaymentService::class)->transfer($invoice->corrects, $invoice);
            }

            return $invoice;
        });
    }

    /**
     * Annule une facture envoyée en émettant un avoir du même montant.
     * La facture d'origine reste consultable, avec la mention « annulée ».
     */
    public function cancel(Invoice $invoice, ?string $reason = null): Invoice
    {
        abort_unless($invoice->isCancellable(), 409, 'Seule une facture envoyée peut être annulée.');

        return DB::transaction(function () use ($invoice, $reason) {
            $invoice->loadMissing('lines');

            $credit = new Invoice($invoice->only([
                'client_id', 'worksite_id', 'discount_type', 'discount_value', 'vat_regime', 'internal_notes',
            ]));
            $credit->kind = 'credit';
            $credit->status = 'draft';
            $credit->quote_id = $invoice->quote_id;
            $credit->cancels_id = $invoice->id;
            $credit->title = "Annulation de la facture {$invoice->number}";
            $credit->notes = $reason ? "Motif : $reason" : null;
            $credit->created_by = auth()->id();
            $credit->save();
            $this->replaceLines($credit, $invoice->lines->map(fn ($line) => $line->only($line->getFillable()))->all());
            $this->recalculate($credit);
            $this->send($credit);

            $invoice->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
            ActivityLogger::log('invoice.cancelled', "Facture {$invoice->number} annulée par l'avoir {$credit->number}", $invoice, array_filter(['motif' => $reason]));

            return $credit;
        });
    }

    /**
     * « Modifier » une facture envoyée : avoir d'annulation + brouillon de la
     * facture corrigée (qui recevra un nouveau numéro à l'envoi).
     */
    public function correct(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $this->cancel($invoice, 'facture corrigée');

            $copy = new Invoice($invoice->only([
                'client_id', 'worksite_id', 'title', 'work_period', 'show_bank', 'due_days', 'discount_type', 'discount_value', 'vat_regime',
                'payment_terms', 'notes', 'internal_notes',
            ]));
            $copy->kind = $invoice->kind;
            $copy->status = 'draft';
            $copy->quote_id = $invoice->quote_id;
            $copy->percent = $invoice->percent;
            $copy->corrects_id = $invoice->id;
            $copy->created_by = auth()->id();
            $copy->save();
            $this->replaceLines($copy, $invoice->lines->map(fn ($line) => $line->only($line->getFillable()))->all());
            ActivityLogger::log('invoice.correcting', "Correction de la facture {$invoice->number} en préparation", $copy);

            return $this->recalculate($copy);
        });
    }

    /** Raisons qui empêchent l'envoi (liste vide = envoi possible). */
    public function sendingProblems(Invoice $invoice): array
    {
        $problems = [];
        $items = $invoice->lines->where('type', 'item');

        if ($items->isEmpty()) {
            $problems[] = 'Ajoutez au moins une prestation avant d\'envoyer la facture.';
        }
        if ($items->where('is_optional', true)->isNotEmpty()) {
            $problems[] = 'Une facture ne peut pas contenir d\'option : décochez « Option » sur les lignes choisies par le client ou supprimez-les.';
        }
        if ($invoice->total_ttc < 0) {
            $problems[] = 'Le total de la facture est négatif : vérifiez les déductions.';
        }

        return $problems;
    }

    /** Montant déjà facturé sur un devis (factures envoyées non annulées, hors brouillons). */
    public function invoicedAmount(Quote $quote): int
    {
        return (int) $quote->invoices()->whereIn('status', Invoice::ISSUED)->sum('total_ttc');
    }

    /**
     * Lignes d'un acompte ou d'une situation : le pourcentage de la base HT du
     * devis, taux de TVA par taux de TVA (remise globale comprise).
     *
     * @return list<array<string, mixed>>
     */
    private function percentLines(Quote $quote, string $kind, int $percent): array
    {
        $totals = $this->breakdown($quote);
        $bases = $quote->isFranchise()
            ? [$this->mainRate($quote) => $totals['total_ht']]
            : array_map(fn ($vat) => $vat['base'], $totals['vat']);
        $bases = array_filter($bases);

        $label = $kind === 'deposit' ? 'Acompte' : 'Situation de travaux';
        $count = $kind === 'progress' ? $quote->invoices()->where('kind', 'progress')->whereIn('status', Invoice::ISSUED)->count() + 1 : null;
        $title = $label.($count ? " n° $count" : '').' : '.Percent::format($percent)." du devis {$quote->number}";

        return collect($bases)->map(fn (int $base, int $rate) => [
            'type' => 'item',
            'title' => $title.(count($bases) > 1 && ! $quote->isFranchise() ? ' — travaux à '.Percent::format($rate).' de TVA' : ''),
            'description' => collect([
                $quote->title,
                'Montant HT du devis'.(count($bases) > 1 ? ' à ce taux' : '').' : '.Money::format($base),
            ])->filter()->implode("\n"),
            'quantity' => 1000,
            'unit' => 'forfait',
            'unit_price' => intdiv($base * $percent + 5000, 10000),
            'vat_rate' => $rate,
        ])->values()->all();
    }

    /**
     * Déductions de la facture de solde : chaque ligne des acomptes et
     * situations envoyés est reprise en négatif, au même taux de TVA.
     *
     * @return list<array<string, mixed>>
     */
    private function deductionLines(Quote $quote): array
    {
        $previous = $quote->invoices()->whereIn('kind', ['deposit', 'progress'])->whereIn('status', Invoice::ISSUED)->with('lines')->get();
        if ($previous->isEmpty()) {
            return [];
        }

        $lines = [['type' => 'section', 'title' => 'Déjà facturé', 'quantity' => 0, 'unit_price' => 0, 'vat_rate' => 0]];
        foreach ($previous as $invoice) {
            foreach ($invoice->lines->where('type', 'item') as $line) {
                $lines[] = [
                    'type' => 'item',
                    'title' => "Déduction {$invoice->kindLabel()} {$invoice->number} du {$invoice->issue_date->format('d/m/Y')}",
                    'quantity' => 1000,
                    'unit' => 'forfait',
                    'unit_price' => -$line->total_ht,
                    'vat_rate' => $line->vat_rate,
                ];
            }
        }

        return $lines;
    }

    /**
     * Remise globale du devis convertie en lignes négatives, une par taux de TVA.
     *
     * @return list<array<string, mixed>>
     */
    private function discountLines(Quote $quote): array
    {
        $totals = $this->breakdown($quote);
        if ($totals['discount'] === 0) {
            return [];
        }

        $label = 'Remise'.($quote->discount_type === 'percent' ? ' de '.Percent::format((int) $quote->discount_value) : '');
        if ($quote->isFranchise()) {
            $shares = [$this->mainRate($quote) => $totals['discount']];
        } else {
            $before = [];
            foreach ($quote->lines as $index => $line) {
                if ($line->isItem() && ! $line->is_optional && ! $line->is_offered) {
                    $before[$line->vat_rate] = ($before[$line->vat_rate] ?? 0) + $totals['lines'][$index];
                }
            }
            $shares = array_filter(array_map(fn ($rate) => $before[$rate] - $totals['vat'][$rate]['base'], array_combine(array_keys($before), array_keys($before))));
        }

        return collect($shares)->map(fn (int $amount, int $rate) => [
            'type' => 'item',
            'title' => $label.(count($shares) > 1 ? ' (TVA '.Percent::format($rate).')' : ''),
            'quantity' => 1000,
            'unit' => 'forfait',
            'unit_price' => -$amount,
            'vat_rate' => $rate,
        ])->values()->all();
    }

    /** Taux de TVA le plus utilisé du devis (sert de taux par défaut en franchise). */
    private function mainRate(Quote $quote): int
    {
        return (int) ($quote->lines->where('type', 'item')->groupBy('vat_rate')->sortByDesc(fn ($lines) => $lines->count())->keys()->first() ?? 0);
    }
}

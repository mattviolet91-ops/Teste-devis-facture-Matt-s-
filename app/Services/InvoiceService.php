<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Expense;
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
            $invoice->project_id = $quote->project_id;
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
                    $discount = $this->discountLines($quote);
                    // Devis découpé en sections : la remise globale forme sa propre section,
                    // sinon elle serait comptée dans le sous-total de la dernière.
                    if ($discount && $quote->lines->contains('type', 'section')) {
                        array_unshift($discount, ['type' => 'section', 'title' => 'Remise', 'quantity' => 0, 'unit_price' => 0, 'vat_rate' => 0]);
                    }
                    $lines = array_merge($lines, $discount, $this->deductionLines($quote));
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

    /**
     * Facture d'un montant libre (TTC), acompte ou facture simple, rattachée ou non à un devis.
     * Le total TTC est exactement le montant : si la TVA ne tombe pas juste au centime,
     * une ligne « Arrondi » (sans TVA) de 1 centime complète.
     */
    public function createForAmount(Client $client, ?Quote $quote, string $kind, string $label, int $amountTtc, int $vatRate): Invoice
    {
        if (! in_array($kind, ['deposit', 'standard'], true) || $amountTtc <= 0) {
            throw new InvalidArgumentException('Montant ou type de facture invalide.');
        }

        return DB::transaction(function () use ($client, $quote, $kind, $label, $amountTtc, $vatRate) {
            $invoice = $this->blank($client->id, $quote?->worksite_id);
            if ($quote) {
                $invoice->fill($quote->only(['vat_regime', 'show_bank']));
                $invoice->quote_id = $quote->id;
                $invoice->project_id = $quote->project_id;
            }
            $invoice->title = $label;
            $invoice->kind = $kind;
            $invoice->due_days = 0;
            $invoice->created_by = auth()->id();
            $invoice->save();

            $line = [
                'type' => 'item',
                'title' => $label,
                'description' => $quote ? ($kind === 'deposit' ? 'Acompte' : 'Règlement').' sur le devis n° '.$quote->number.($quote->title ? ' — '.$quote->title : '') : null,
                'quantity' => 1000,
                'unit' => 'forfait',
                'vat_rate' => $vatRate,
            ];
            $this->replaceLines($invoice, $this->amountLines($line, $amountTtc, $vatRate, $invoice->isFranchise()));
            ActivityLogger::log('invoice.created', Invoice::KINDS[$kind].' de '.Money::format($amountTtc).' préparée (montant libre)', $invoice);

            return $this->recalculate($invoice);
        });
    }

    /**
     * Ligne(s) dont le total TTC vaut exactement le montant.
     *
     * @param  array<string, mixed>  $line
     * @return list<array<string, mixed>>
     */
    public function amountLines(array $line, int $amountTtc, int $vatRate, bool $franchise): array
    {
        $total = fn (int $ht) => $this->calculator->calculate([$line + ['unit_price' => $ht]], null, 0, $franchise)['total_ttc'];
        if ($franchise || $vatRate <= 0) {
            return [$line + ['unit_price' => $amountTtc]];
        }

        $guess = intdiv($amountTtc * 10000 + intdiv(10000 + $vatRate, 2), 10000 + $vatRate);
        $below = null;
        for ($ht = $guess - 3; $ht <= $guess + 3; $ht++) {
            $ttc = $total($ht);
            if ($ttc === $amountTtc) {
                return [$line + ['unit_price' => $ht]];
            }
            if ($ttc < $amountTtc) {
                $below = $ht;
            }
        }

        $below ??= $guess - 3;

        return [
            $line + ['unit_price' => $below],
            ['type' => 'item', 'title' => 'Arrondi', 'quantity' => 1000, 'unit' => 'forfait', 'unit_price' => $amountTtc - $total($below), 'vat_rate' => 0],
        ];
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
            // Devis signé chez un particulier il y a moins de 7 jours : payable à partir du 8e jour.
            if (($from = $invoice->paymentWaitUntil()) && $from->gt($invoice->due_date)) {
                $invoice->due_date = $from;
            }
            $invoice->save();

            ActivityLogger::log('invoice.sent', "{$invoice->fullTitle()} envoyée", $invoice);
            app(PdfService::class)->freeze($invoice);

            // Facture corrigée : les règlements reçus et les frais notés sur la facture d'origine la suivent.
            if ($invoice->corrects) {
                Expense::query()->where('invoice_id', $invoice->corrects->id)->update(['invoice_id' => $invoice->id]);
                app(PaymentService::class)->transfer($invoice->corrects, $invoice);
            }

            // Rappels d'entretien des prestations réalisées (ex. démoussage dans 3 ans).
            app(MaintenanceService::class)->fromInvoice($invoice);

            if ($invoice->quote && ! $invoice->isCredit() && $invoice->kind !== 'final') {
                $this->refreshFinalDrafts($invoice->quote, $invoice);
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
            app(MaintenanceService::class)->forgetInvoice($invoice);
            if ($invoice->quote && $invoice->kind !== 'final') {
                $this->refreshFinalDrafts($invoice->quote);
            }
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
            $copy->project_id = $invoice->project_id;
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
        if (($over = $this->overInvoiced($invoice)) > 0) {
            $problems[] = 'Avec cette facture, le total facturé dépasserait le montant du devis '.$invoice->quote->number.' de '.Money::format($over)
                .($invoice->kind === 'final' ? ' : un acompte a été facturé après la préparation de ce solde. Supprimez ce brouillon et refaites « Facturer → Solde ».' : '. Réduisez le montant, ou créez une facture sans la rattacher au devis pour des travaux en plus.');
        }

        return $problems;
    }

    /** Tolérance d'arrondi (centimes) entre le devis et la somme de ses factures. */
    private const ROUNDING_TOLERANCE = 5;

    /** De combien cette facture ferait dépasser le montant de son devis (0 si elle ne le dépasse pas). */
    public function overInvoiced(Invoice $invoice): int
    {
        $quote = $invoice->isCredit() ? null : $invoice->quote;
        if (! $quote) {
            return 0;
        }
        $others = (int) $quote->invoices()->where('kind', '!=', 'credit')->whereIn('status', Invoice::ISSUED)->whereKeyNot($invoice->id)->sum('total_ttc');
        $over = $others + $invoice->total_ttc - $quote->total_ttc;

        return $over > self::ROUNDING_TOLERANCE ? $over : 0;
    }

    /** Ce qu'il reste à facturer sur un devis. */
    public function remainingToInvoice(Quote $quote): int
    {
        return max(0, $quote->total_ttc - (int) $quote->invoices()->where('kind', '!=', 'credit')->whereIn('status', Invoice::ISSUED)->sum('total_ttc'));
    }

    /**
     * Brouillons de facture de solde du devis : leurs déductions sont refaites quand une autre
     * facture du devis est émise ou annulée (les autres lignes du brouillon ne sont pas touchées).
     */
    public function refreshFinalDrafts(Quote $quote, ?Invoice $except = null): void
    {
        $drafts = $quote->invoices()->where('kind', 'final')->where('status', 'draft')->when($except, fn ($q) => $q->whereKeyNot($except->id))->with('lines')->get();
        foreach ($drafts as $draft) {
            $kept = $draft->lines->reject(fn ($line) => ($line->type === 'section' && $line->title === 'Déjà facturé') || ($line->type === 'item' && str_starts_with((string) $line->title, 'Déduction ')))
                ->map(fn ($line) => $line->only($line->getFillable()))->values()->all();
            $this->replaceLines($draft, array_merge($kept, $this->deductionLines($quote)));
            $this->recalculate($draft);
        }
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
        $previous = $quote->invoices()->whereIn('kind', ['deposit', 'progress', 'standard'])->whereIn('status', Invoice::ISSUED)->with('lines')->orderBy('id')->get();
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

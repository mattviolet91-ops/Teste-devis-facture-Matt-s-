<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Quote;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie d'un devis : enregistrement du brouillon, envoi (numérotation),
 * réponse du client, nouvelle version, duplication, expiration.
 */
class QuoteService
{
    public function __construct(
        private readonly DocumentCalculator $calculator,
        private readonly NumberGenerator $numbers,
        private readonly Settings $settings,
    ) {}

    /**
     * Enregistre l'en-tête et remplace toutes les lignes du brouillon.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    public function saveDraft(Quote $quote, array $attributes, array $lines): Quote
    {
        return DB::transaction(function () use ($quote, $attributes, $lines) {
            $quote->fill($attributes);
            $quote->vat_regime = $this->settings->get('vat.regime');
            $quote->created_by ??= auth()->id();
            $quote->save();

            $quote->lines()->delete();
            foreach (array_values($lines) as $position => $line) {
                $quote->lines()->create($line + ['position' => $position + 1]);
            }

            return $this->recalculate($quote);
        });
    }

    /** Recalcule et enregistre les totaux à partir des lignes. */
    public function recalculate(Quote $quote): Quote
    {
        $lines = $quote->lines()->get();
        $result = $this->calculator->calculate(
            $lines->map->toCalculation()->all(),
            $quote->discount_type,
            (int) $quote->discount_value,
            $quote->isFranchise(),
        );

        foreach ($lines->values() as $index => $line) {
            $line->update(['total_ht' => $result['lines'][$index]]);
        }

        $quote->forceFill([
            'total_ht' => $result['total_ht'],
            'total_vat' => $result['total_vat'],
            'total_ttc' => $result['total_ttc'],
        ])->save();

        return $quote->setRelation('lines', $lines);
    }

    /** Totaux détaillés pour l'affichage (sous-totaux de sections, TVA par taux…). */
    public function breakdown(Quote $quote): array
    {
        return $this->calculator->calculate(
            $quote->lines->map->toCalculation()->values()->all(),
            $quote->discount_type,
            (int) $quote->discount_value,
            $quote->isFranchise(),
        );
    }

    /** Envoi : attribue le numéro, fixe les dates et remplace l'éventuelle version précédente. */
    public function send(Quote $quote): Quote
    {
        return DB::transaction(function () use ($quote) {
            $quote->number ??= $this->numbers->next('quote');
            $quote->status = 'sent';
            $quote->sent_at = now();
            $quote->issue_date = today();
            $quote->valid_until = today()->addDays($quote->validity_days);
            $quote->save();

            if ($quote->replaces && $quote->replaces->status !== 'replaced') {
                $quote->replaces->forceFill(['status' => 'replaced', 'replaced_by_id' => $quote->id])->save();
                ActivityLogger::log('quote.replaced', "Devis {$quote->replaces->number} remplacé par {$quote->number}", $quote->replaces);
            }

            ActivityLogger::log('quote.sent', "Devis {$quote->number} envoyé", $quote);

            return $quote;
        });
    }

    public function accept(Quote $quote): Quote
    {
        return DB::transaction(function () use ($quote) {
            $quote->forceFill(['status' => 'accepted', 'accepted_at' => now(), 'refused_at' => null, 'refusal_reason' => null])->save();

            // Le prospect devient client au premier devis accepté.
            if ($quote->client && $quote->client->status !== 'client') {
                $quote->client->forceFill(['status' => 'client'])->save();
            }

            ActivityLogger::log('quote.accepted', "Devis {$quote->number} accepté", $quote);

            return $quote;
        });
    }

    public function refuse(Quote $quote, ?string $reason): Quote
    {
        $quote->forceFill(['status' => 'refused', 'refused_at' => now(), 'refusal_reason' => $reason ?: null])->save();
        ActivityLogger::log('quote.refused', "Devis {$quote->number} refusé", $quote, array_filter(['motif' => $reason]));

        return $quote;
    }

    /**
     * Nouvelle version d'un devis déjà envoyé : un brouillon identique qui, une
     * fois envoyé, recevra un nouveau numéro et remplacera l'ancien.
     */
    public function revise(Quote $quote): Quote
    {
        $copy = $this->copy($quote, $quote->client_id, $quote->worksite_id);
        $copy->replaces_id = $quote->id;
        $copy->save();

        ActivityLogger::log('quote.revised', "Nouvelle version en préparation pour le devis {$quote->number}", $quote);

        return $copy;
    }

    public function duplicate(Quote $quote, Client $client): Quote
    {
        $worksite = $client->id === $quote->client_id ? $quote->worksite_id : $client->worksites()->value('id');
        $copy = $this->copy($quote, $client->id, $worksite);
        ActivityLogger::log('quote.duplicated', 'Devis dupliqué depuis '.$quote->displayNumber(), $copy);

        return $copy;
    }

    /** Passe en « expiré » les devis envoyés dont la validité est dépassée. */
    public function expireOverdue(): int
    {
        return Quote::query()->where('status', 'sent')->whereDate('valid_until', '<', today())->update(['status' => 'expired']);
    }

    private function copy(Quote $quote, int $clientId, ?int $worksiteId): Quote
    {
        return DB::transaction(function () use ($quote, $clientId, $worksiteId) {
            $copy = new Quote($quote->only([
                'title', 'validity_days', 'discount_type', 'discount_value', 'work_start', 'work_duration',
                'payment_terms', 'notes', 'internal_notes',
            ]));
            $copy->client_id = $clientId;
            $copy->worksite_id = $worksiteId;
            $copy->vat_regime = $this->settings->get('vat.regime');
            $copy->created_by = auth()->id();
            $copy->save();

            foreach ($quote->lines as $line) {
                $copy->lines()->create($line->only($line->getFillable()));
            }

            return $this->recalculate($copy);
        });
    }
}

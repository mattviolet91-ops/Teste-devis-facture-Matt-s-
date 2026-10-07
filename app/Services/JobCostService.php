<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Quote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Frais classés par chantier. Un chantier regroupe un ou plusieurs devis acceptés
 * et toutes leurs factures (acompte, situation, solde…), ou des factures faites sans
 * devis. Chaque devis accepté a d'abord son propre chantier ; on peut le rattacher
 * au chantier d'un autre devis du même client. Les frais se notent à tout moment.
 * Les frais sans chantier (outillage, carburant…) sont des « frais généraux ».
 */
class JobCostService
{
    /** Un devis devient un chantier (frais possibles) dès qu'il est accepté ou facturé. */
    public function isJob(Quote $quote): bool
    {
        return $quote->status === 'accepted' || $quote->invoices()->whereIn('status', Invoice::ISSUED)->exists();
    }

    /** Chantier d'un devis (créé à la première demande). */
    public function projectForQuote(Quote $quote): Project
    {
        if ($quote->project_id && $quote->project) {
            return $quote->project;
        }

        return DB::transaction(function () use ($quote) {
            $project = Project::query()->create([
                'client_id' => $quote->client_id,
                'worksite_id' => $quote->worksite_id,
                'title' => mb_substr(trim((string) $quote->title) ?: 'Devis '.$quote->number, 0, 160),
            ]);
            $quote->forceFill(['project_id' => $project->id])->saveQuietly();
            Invoice::query()->where('quote_id', $quote->id)->whereNull('project_id')->update(['project_id' => $project->id]);
            Expense::query()->where('quote_id', $quote->id)->whereNull('project_id')->update(['project_id' => $project->id]);
            $quote->setRelation('project', $project);

            return $project;
        });
    }

    /** Chantier d'une facture : celui de son devis, sinon le sien. */
    public function projectForInvoice(Invoice $invoice): Project
    {
        if ($invoice->project_id && $invoice->project) {
            return $invoice->project;
        }
        if ($invoice->quote_id && $invoice->quote) {
            $project = $this->projectForQuote($invoice->quote);
        } else {
            $project = Project::query()->create([
                'client_id' => $invoice->client_id,
                'worksite_id' => $invoice->worksite_id,
                'title' => mb_substr(trim((string) $invoice->title) ?: 'Facture '.($invoice->number ?? ''), 0, 160),
            ]);
            Expense::query()->whereNull('project_id')->whereNull('quote_id')->where('invoice_id', $invoice->id)->update(['project_id' => $project->id]);
        }
        $invoice->forceFill(['project_id' => $project->id])->saveQuietly();
        $invoice->setRelation('project', $project);

        return $project;
    }

    /**
     * Range un devis (et ses factures, et ses frais) dans un autre chantier du même client.
     * $target null : le devis repart dans son propre chantier.
     */
    public function attachQuote(Quote $quote, ?Project $target): Project
    {
        return DB::transaction(function () use ($quote, $target) {
            $old = $this->projectForQuote($quote);
            $target ??= Project::query()->create([
                'client_id' => $quote->client_id, 'worksite_id' => $quote->worksite_id,
                'title' => mb_substr(trim((string) $quote->title) ?: 'Devis '.$quote->number, 0, 160),
            ]);
            if ($target->is($old)) {
                return $old;
            }
            $quote->forceFill(['project_id' => $target->id])->saveQuietly();
            Invoice::query()->where('quote_id', $quote->id)->update(['project_id' => $target->id]);
            Expense::query()->where('quote_id', $quote->id)->update(['project_id' => $target->id]);
            $this->dropIfEmpty($old, $target);

            return $target;
        });
    }

    /** Même chose pour une facture faite sans devis. */
    public function attachInvoice(Invoice $invoice, ?Project $target): Project
    {
        return DB::transaction(function () use ($invoice, $target) {
            $old = $this->projectForInvoice($invoice);
            $target ??= Project::query()->create([
                'client_id' => $invoice->client_id, 'worksite_id' => $invoice->worksite_id,
                'title' => mb_substr(trim((string) $invoice->title) ?: 'Facture '.$invoice->number, 0, 160),
            ]);
            if ($target->is($old)) {
                return $old;
            }
            $invoice->forceFill(['project_id' => $target->id])->saveQuietly();
            Expense::query()->whereNull('quote_id')->where('invoice_id', $invoice->id)->update(['project_id' => $target->id]);
            $this->dropIfEmpty($old, $target);

            return $target;
        });
    }

    /** Autres chantiers du même client, pour y rattacher un devis ou une facture. */
    public function otherProjects(?int $clientId, Project $except): Collection
    {
        return $clientId
            ? Project::query()->where('client_id', $clientId)->whereKeyNot($except->id)->latest('id')->get()
            : collect();
    }

    /**
     * Chantiers en cours ou facturés, du plus récent au plus ancien.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function jobs(?string $search = null): Collection
    {
        // Devis acceptés et factures émises sans chantier encore : rangés maintenant.
        $billedQuoteIds = Invoice::query()->whereIn('status', Invoice::ISSUED)->whereNotNull('quote_id')->pluck('quote_id')->unique();
        Quote::query()->whereNull('project_id')
            ->where(fn ($q) => $q->where('status', 'accepted')->orWhereIn('id', $billedQuoteIds))
            ->get()->each(fn (Quote $q) => $this->projectForQuote($q));
        Invoice::query()->whereNull('project_id')->whereIn('status', Invoice::ISSUED)->where('kind', '!=', 'credit')
            ->get()->each(fn (Invoice $i) => $this->projectForInvoice($i));

        $projects = Project::query()
            ->with(['client', 'worksite', 'quotes', 'invoices', 'expenses'])
            ->when($search, function ($query) use ($search) {
                $query->where(fn ($w) => $w->where('title', 'like', '%'.$search.'%')
                    ->orWhereHas('client', fn ($c) => $c->search($search))
                    ->orWhereHas('quotes', fn ($q) => $q->search($search))
                    ->orWhereHas('invoices', fn ($i) => $i->search($search)));
            })
            ->get();

        return $projects
            ->map(fn (Project $p) => $this->summaryOf($p))
            ->filter(fn (array $job) => $job['quotes']->isNotEmpty() || $job['invoices']->isNotEmpty())
            ->sortByDesc('last')
            ->values();
    }

    /** Un chantier, avec ses frais (du plus récent au plus ancien). */
    public function job(Project $project): array
    {
        $project->load(['client', 'worksite', 'quotes', 'invoices', 'expenses' => fn ($q) => $q->with('invoice', 'quote')->latest('spent_on')->latest('id')]);

        return $this->summaryOf($project) + ['expenses' => $project->expenses];
    }

    /** Chantier d'une facture (pour la section « Frais » de la page facture). */
    public function forInvoice(Invoice $invoice): array
    {
        return $this->job($this->projectForInvoice($invoice));
    }

    /** Frais généraux : sans chantier. */
    public function general(): array
    {
        $expenses = Expense::query()->whereNull('project_id')->whereNull('quote_id')->whereNull('invoice_id')->latest('spent_on')->latest('id')->get();

        return ['expenses' => $expenses, 'expenses_total' => (int) $expenses->sum(fn (Expense $e) => $e->amountHt())];
    }

    /** @return array<string, mixed> */
    private function summaryOf(Project $project): array
    {
        $quotes = $project->quotes->where('status', 'accepted')->sortBy('id')->values();
        $invoices = $project->invoices->whereIn('status', Invoice::ISSUED)->where('kind', '!=', 'credit')->sortBy('id')->values();
        $expenses = $project->expenses;

        $invoiced = (int) $invoices->sum('total_ht');
        // Prévu : les devis acceptés, plus les factures faites sans devis.
        $planned = (int) $quotes->sum('total_ht') + (int) $invoices->whereNull('quote_id')->sum('total_ht');
        $planned = max($planned, $invoiced);
        $costs = (int) $expenses->sum(fn (Expense $e) => $e->amountHt());
        $first = $quotes->first() ?? $invoices->first() ?? $project->quotes->first();

        return [
            'key' => 'chantier-'.$project->id,
            'project' => $project,
            'client' => $project->client,
            'title' => $project->title,
            'address' => $project->worksite?->fullAddress(),
            'quotes' => $quotes,
            'invoices' => $invoices,
            'numbers' => $invoices->pluck('number')->filter()->implode(', '),
            'planned' => $planned,
            'invoiced' => $invoiced,
            'billed' => $invoiced > 0,
            'fully' => $invoiced > 0 && $invoiced >= $planned,
            'percent' => $planned > 0 ? min(100, (int) round($invoiced * 100 / $planned)) : 100,
            'expenses_total' => $costs,
            'expenses_count' => $expenses->count(),
            'remaining' => $invoiced - $costs,
            'expected' => $planned - $costs,
            'franchise' => $first ? $first->isFranchise() : true,
            'last' => $invoices->max('issue_date') ?? $quotes->max('accepted_at') ?? $project->updated_at,
            'url' => route('expenses.project', $project),
        ];
    }

    /** Ancien chantier vidé : ses frais restants suivent dans le nouveau, puis il disparaît. */
    private function dropIfEmpty(Project $project, Project $target): void
    {
        if (! $project->quotes()->exists() && ! $project->invoices()->exists()) {
            $project->expenses()->update(['project_id' => $target->id]);
            $project->delete();
        }
    }
}

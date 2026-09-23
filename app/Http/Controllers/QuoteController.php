<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ProvidesEditorData;
use App\Http\Requests\QuoteRequest;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Quote;
use App\Models\TextTemplate;
use App\Services\ActivityLogger;
use App\Services\QuoteService;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class QuoteController extends Controller
{
    use ProvidesEditorData;

    public const FILTERS = [
        'all' => 'Tous',
        'draft' => 'Brouillons',
        'sent' => 'En attente',
        'accepted' => 'Acceptés',
        'closed' => 'Refusés / expirés',
    ];

    public function __construct(private readonly QuoteService $quotes) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_keys(self::FILTERS))],
        ]);
        $filters['status'] ??= 'all';

        $quotes = Quote::query()
            ->with('client')
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->search($q))
            ->when($filters['status'] === 'closed', fn ($query) => $query->whereIn('status', ['refused', 'expired', 'replaced']))
            ->when(! in_array($filters['status'], ['all', 'closed'], true), fn ($query) => $query->where('status', $filters['status']))
            ->latest('updated_at')
            ->paginate(25)
            ->withQueryString();

        return view('quotes.index', compact('quotes', 'filters'));
    }

    public function create(Request $request, Settings $settings): View
    {
        $client = $request->integer('client') ? Client::query()->find($request->integer('client')) : null;

        $quote = new Quote([
            'client_id' => $client?->id,
            'worksite_id' => $client?->worksites()->value('id'),
            'validity_days' => (int) $settings->get('documents.quote_validity_days', 30),
            'payment_terms' => TextTemplate::query()->ofType('payment_terms')->where('is_default', true)->value('body'),
            'notes' => TextTemplate::query()->ofType('note')->where('is_default', true)->pluck('body')->implode("\n"),
        ]);
        $quote->vat_regime = $settings->get('vat.regime');

        return view('quotes.edit', ['quote' => $quote] + $this->editorData($quote, $settings));
    }

    public function store(QuoteRequest $request): RedirectResponse
    {
        $quote = $this->quotes->saveDraft(new Quote(['status' => 'draft']), $request->quoteAttributes(), $request->lines());
        ActivityLogger::log('quote.created', 'Devis créé (brouillon)', $quote);

        return redirect()->route('quotes.show', $quote)->with('status', 'Brouillon enregistré.');
    }

    public function show(Quote $quote): View
    {
        $quote->load(['client', 'worksite', 'lines', 'replaces', 'replacedBy', 'invoices']);

        $history = ActivityLog::query()
            ->where('subject_type', $quote->getMorphClass())
            ->where('subject_id', $quote->id)
            ->latest('id')
            ->limit(20)
            ->get();

        return view('quotes.show', [
            'quote' => $quote,
            'totals' => $this->quotes->breakdown($quote),
            'history' => $history,
            'clients' => Client::query()->alphabetical()->get(['id', 'type', 'civility', 'first_name', 'last_name', 'company_name']),
        ]);
    }

    public function edit(Quote $quote, Settings $settings): View|RedirectResponse
    {
        if (! $quote->isDraft()) {
            return redirect()->route('quotes.show', $quote)
                ->with('status', 'Un devis envoyé ne se modifie plus : créez une nouvelle version.');
        }

        return view('quotes.edit', ['quote' => $quote->load('lines')] + $this->editorData($quote, $settings));
    }

    public function update(QuoteRequest $request, Quote $quote): RedirectResponse
    {
        abort_unless($quote->isDraft(), 403, 'Seul un brouillon peut être modifié.');

        $this->quotes->saveDraft($quote, $request->quoteAttributes(), $request->lines());
        ActivityLogger::log('quote.updated', 'Brouillon modifié', $quote);

        return redirect()->route('quotes.show', $quote)->with('status', 'Brouillon enregistré.');
    }

    public function destroy(Quote $quote): RedirectResponse
    {
        abort_unless($quote->isDraft(), 403, 'Seul un brouillon peut être supprimé.');

        $quote->delete();
        ActivityLogger::log('quote.deleted', 'Brouillon de devis mis à la corbeille', $quote);

        return redirect()->route('quotes.index')->with('status', 'Brouillon placé dans la corbeille.');
    }

    public function send(Quote $quote): RedirectResponse
    {
        abort_unless($quote->isDraft(), 403);

        if ($quote->lines()->where('type', 'item')->doesntExist()) {
            return back()->withErrors(['send' => 'Ajoutez au moins une prestation avant d\'envoyer le devis.']);
        }

        $this->quotes->send($quote);

        return redirect()->route('quotes.show', $quote)->with('status', "Devis {$quote->number} marqué comme envoyé.");
    }

    public function accept(Quote $quote): RedirectResponse
    {
        abort_unless($quote->awaitsAnswer(), 403);
        $this->quotes->accept($quote);

        return redirect()->route('quotes.show', $quote)->with('status', 'Devis accepté.');
    }

    public function refuse(Request $request, Quote $quote): RedirectResponse
    {
        abort_unless($quote->awaitsAnswer(), 403);
        $data = $request->validate(['refusal_reason' => ['nullable', 'string', 'max:500']]);
        $this->quotes->refuse($quote, $data['refusal_reason'] ?? null);

        return redirect()->route('quotes.show', $quote)->with('status', 'Devis marqué comme refusé.');
    }

    public function revise(Quote $quote): RedirectResponse
    {
        abort_if($quote->isDraft() || $quote->status === 'replaced', 403);

        $pending = Quote::query()->where('replaces_id', $quote->id)->where('status', 'draft')->first();
        $draft = $pending ?? $this->quotes->revise($quote);

        return redirect()->route('quotes.edit', $draft)
            ->with('status', "Nouvelle version du devis {$quote->number} : elle recevra un nouveau numéro à l'envoi.");
    }

    public function duplicate(Request $request, Quote $quote): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
        ]);

        $copy = $this->quotes->duplicate($quote, Client::query()->findOrFail($data['client_id']));

        return redirect()->route('quotes.edit', $copy)->with('status', 'Copie créée en brouillon.');
    }
}

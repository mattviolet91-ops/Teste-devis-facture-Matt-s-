<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\Client;
use App\Services\ActivityLogger;
use App\Services\QuickQuoteService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API réservée à la création de devis BROUILLONS (par Claude, avec une clé du gérant).
 * Aucune lecture des factures, aucun envoi : le gérant vérifie et envoie lui-même.
 */
class QuoteApiController extends Controller
{
    public function clients(Request $request): JsonResponse
    {
        $q = (string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']])['q'];

        return response()->json(Client::query()->search($q)->limit(10)->get()->map(fn (Client $c) => [
            'id' => $c->id,
            'nom' => $c->displayName(),
            'ville' => $c->city,
            'code_postal' => $c->postal_code,
        ])->values());
    }

    public function catalog(Request $request): JsonResponse
    {
        $q = (string) ($request->validate(['q' => ['nullable', 'string', 'max:80']])['q'] ?? '');

        return response()->json(CatalogItem::query()->active()->when($q !== '', fn ($query) => $query->search($q))
            ->orderBy('position')->limit(50)->get()->map(fn (CatalogItem $item) => [
                'id' => $item->id,
                'nom' => $item->name,
                'unite' => $item->unit,
                'prix_ht' => $item->unit_price ? Money::plain($item->unit_price) : null,
            ])->values());
    }

    /** Aperçu : rien n'est créé. */
    public function preview(Request $request, QuickQuoteService $service): JsonResponse
    {
        $data = $this->validated($request);

        return response()->json($this->present($service->parse($data['texte'], $data['client_id'] ?? null)));
    }

    public function store(Request $request, QuickQuoteService $service): JsonResponse
    {
        $data = $this->validated($request);
        $parsed = $service->parse($data['texte'], $data['client_id'] ?? null);
        if ($parsed['errors'] !== []) {
            return response()->json($this->present($parsed), 422);
        }

        $quote = $service->create($parsed, $data['objet'] ?? null);
        ActivityLogger::log('quote.api', 'Devis brouillon créé par Claude (clé d\'accès)', $quote);

        return response()->json([
            'id' => $quote->id,
            'statut' => 'brouillon',
            'lien' => route('quotes.show', $quote),
            'client' => $quote->client?->displayName(),
            'objet' => $quote->title,
            'total_ht' => Money::plain((int) $quote->total_ht),
            'total_ttc' => Money::plain((int) $quote->total_ttc),
        ] + $this->present($parsed), 201);
    }

    /** @return array{texte: string, client_id?: int|null, objet?: string|null} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'texte' => ['required', 'string', 'max:5000'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'objet' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(array $parsed): array
    {
        return [
            'client_trouve' => $parsed['client'] ? ['id' => $parsed['client']->id, 'nom' => $parsed['client']->displayName(), 'ville' => $parsed['client']->city] : null,
            'clients_possibles' => $parsed['client'] ? [] : $parsed['clients']->map(fn (Client $c) => ['id' => $c->id, 'nom' => $c->displayName(), 'ville' => $c->city])->values(),
            'objet_propose' => $parsed['title'],
            'lignes' => array_map(fn ($l) => [
                'designation' => $l['title'],
                'quantite' => $l['quantity'],
                'unite' => $l['unit'],
                'prix_unitaire_ht' => $l['unit_price_cents'] !== null ? Money::plain($l['unit_price_cents']) : null,
                'total_ht' => $l['total'] !== null ? Money::plain($l['total']) : null,
                'bibliotheque' => $l['recognized'],
                'remarques' => $l['warnings'],
            ], $parsed['lines']),
            'erreurs' => $parsed['errors'],
        ];
    }
}

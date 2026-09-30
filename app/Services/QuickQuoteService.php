<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\Client;
use App\Models\Quote;
use App\Models\VatRate;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\Search;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * « Devis express » : une phrase comme
 *   « Mme Martin Massy — démoussage 120 m² à 12 € — faîtage 15 ml à 45 € — évacuation forfait 150 € »
 * devient un devis brouillon. Le client et les prestations sont retrouvés dans
 * l'application ; ce qui n'est pas reconnu est signalé, jamais inventé.
 * Sert à l'écran « Devis express » et à l'accès API (création par Claude).
 */
class QuickQuoteService
{
    /** Unités reconnues dans le texte → code de l'application. */
    private const UNITS = [
        'm²' => 'm²', 'm2' => 'm²', 'mètres carrés' => 'm²', 'metres carres' => 'm²', 'mètre carré' => 'm²', 'metre carre' => 'm²',
        'ml' => 'ml', 'mètres linéaires' => 'ml', 'metres lineaires' => 'ml', 'mètre linéaire' => 'ml', 'metre lineaire' => 'ml',
        'u' => 'u', 'unité' => 'u', 'unités' => 'u', 'unite' => 'u', 'unites' => 'u', 'pièce' => 'u', 'pièces' => 'u', 'pcs' => 'u',
        'h' => 'h', 'heure' => 'h', 'heures' => 'h',
        'forfait' => 'forfait', 'forfaits' => 'forfait', 'ens' => 'forfait', 'ensemble' => 'forfait',
    ];

    public function __construct(
        private readonly QuoteService $quotes,
        private readonly Settings $settings,
    ) {}

    /**
     * Analyse le texte. Rien n'est enregistré.
     *
     * @return array{client: ?Client, clients: Collection<int, Client>, client_query: string, title: ?string, lines: list<array<string, mixed>>, errors: list<string>}
     */
    public function parse(string $text, ?int $clientId = null): array
    {
        $segments = $this->segments($text);
        $clientQuery = (string) array_shift($segments);
        $title = null;

        // « objet : … » ou « pour … » : objet du devis.
        $segments = array_values(array_filter($segments, function (string $segment) use (&$title) {
            if (preg_match('/^(objet|titre)\s*[:\-]\s*(.+)$/iu', $segment, $m)) {
                $title = Str::ucfirst(trim($m[2]));

                return false;
            }

            return true;
        }));

        [$client, $clients] = $this->findClient($clientQuery, $clientId);
        $lines = array_map(fn (string $segment) => $this->parseLine($segment), $segments);

        $errors = [];
        if (! $client) {
            $errors[] = $clients->isEmpty()
                ? 'Client introuvable : « '.$clientQuery.' ». Vérifiez le nom ou créez d\'abord sa fiche.'
                : 'Plusieurs clients correspondent à « '.$clientQuery.' » : choisissez le bon.';
        }
        if ($lines === []) {
            $errors[] = 'Aucune prestation trouvée : séparez-les par un tiret, un point-virgule ou un retour à la ligne.';
        }
        foreach ($lines as $i => $line) {
            foreach ($line['warnings'] as $warning) {
                if ($line['blocking']) {
                    $errors[] = 'Ligne '.($i + 1).' : '.$warning;
                }
            }
        }

        $title ??= $lines ? Str::limit(collect($lines)->pluck('title')->take(2)->implode(' et '), 120, '') : null;

        return ['client' => $client, 'clients' => $clients, 'client_query' => $clientQuery, 'title' => $title, 'lines' => $lines, 'errors' => $errors];
    }

    /**
     * Crée le devis brouillon à partir d'une analyse sans erreur.
     *
     * @param  array{client: ?Client, title: ?string, lines: list<array<string, mixed>>, errors: list<string>}  $parsed
     */
    public function create(array $parsed, ?string $title = null): Quote
    {
        abort_if($parsed['errors'] !== [] || ! $parsed['client'], 422, 'Devis express incomplet.');
        $client = $parsed['client'];

        $quote = $this->quotes->saveDraft(new Quote(['status' => 'draft']), [
            'client_id' => $client->id,
            'worksite_id' => $client->worksites()->value('id'),
            'title' => Str::limit(trim((string) ($title ?: $parsed['title'])), 255, '') ?: null,
            'validity_days' => (int) $this->settings->get('documents.quote_validity_days', 30),
            'show_bank' => false,
            'discount_type' => null,
            'discount_value' => 0,
        ], array_map(fn (array $line) => [
            'type' => 'item',
            'title' => $line['title'],
            'description' => $line['description'],
            // Montants en centimes, quantités en millièmes (format des lignes enregistrées).
            'quantity' => Quantity::parse($line['quantity']),
            'unit' => $line['unit'],
            'unit_price' => (int) $line['unit_price_cents'],
            'vat_rate' => (int) $line['vat_rate'],
            'discount_percent' => 0,
            'is_optional' => false,
            'is_offered' => false,
            'catalog_item_id' => $line['catalog_item_id'],
        ], $parsed['lines']));

        ActivityLogger::log('quote.created', 'Devis express créé (brouillon)', $quote);

        return $quote;
    }

    /** Mots trop courants pour identifier une prestation. */
    private const STOPWORDS = ['des', 'les', 'une', 'aux', 'sur', 'avec', 'dans', 'pour', 'toute', 'tout', 'complete', 'complet'];

    /**
     * Prestation de la bibliothèque, seulement si TOUS les mots importants de la
     * désignation sont dans son nom (jamais d'à-peu-près : un devis doit être juste).
     * Plusieurs possibles : le nom le plus court (le plus proche).
     */
    private function catalogItem(string $designation): ?CatalogItem
    {
        $terms = array_values(array_filter(
            explode(' ', Search::normalize($designation)),
            fn ($t) => mb_strlen($t) >= 4 && ! in_array($t, self::STOPWORDS, true),
        ));
        if ($terms === []) {
            return null;
        }

        return CatalogItem::query()->active()->get()
            ->filter(function (CatalogItem $item) use ($terms) {
                $name = ' '.Search::normalize($item->name).' ';
                foreach ($terms as $term) {
                    // Singulier / pluriel : « gouttiere » trouve « gouttieres ».
                    if (! str_contains($name, ' '.rtrim($term, 's'))) {
                        return false;
                    }
                }

                return true;
            })
            ->sortBy(fn (CatalogItem $item) => mb_strlen($item->name))
            ->first();
    }

    /** @return list<string> */
    private function segments(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Séparateurs : retour à la ligne, point-virgule, tiret entouré d'espaces, tiret long.
        $parts = preg_split('/\n+|;|\s+[—–-]\s+|\s*•\s*/u', $text) ?: [];

        return array_values(array_filter(array_map(fn ($p) => trim($p, " \t,.-—–"), $parts), fn ($p) => $p !== ''));
    }

    /** @return array{0: ?Client, 1: Collection<int, Client>} */
    private function findClient(string $query, ?int $clientId): array
    {
        if ($clientId) {
            $client = Client::query()->find($clientId);

            return [$client, collect($client ? [$client] : [])];
        }

        $clean = trim(preg_replace('/\b(m\.|mme|mlle|mr|madame|monsieur|mademoiselle|m et mme|client|pour)\b/iu', ' ', mb_strtolower($query)) ?? '');
        if ($clean === '') {
            return [null, collect()];
        }

        $matches = Client::query()->search($clean)->limit(6)->get();
        // Plusieurs résultats : le nom exact l'emporte (« Martin » ≠ « Martinez »).
        if ($matches->count() > 1) {
            $exact = $matches->filter(fn (Client $c) => collect(Search::terms($clean))
                ->contains(fn ($term) => in_array($term, Search::terms($c->last_name.' '.$c->company_name), true)));
            if ($exact->count() === 1) {
                return [$exact->first(), $exact->values()];
            }
        }

        return [$matches->count() === 1 ? $matches->first() : null, $matches];
    }

    /** @return array<string, mixed> */
    private function parseLine(string $segment): array
    {
        $rest = ' '.$segment.' ';
        $warnings = [];

        // Prix : le dernier montant suivi de € / euros (« à 12 € », « 12,50€ le m² »).
        $price = null;
        if (preg_match_all('/(\d[\d\s\x{00A0}\x{202F}]*(?:[.,]\d{1,2})?)\s*(?:€|eur\b|euros?\b)(?:\s*(?:ht|ttc)\b)?(?:\s*(?:le|la|par|\/)\s*(?:m²|m2|ml|u|unité|heure|h))?/iu', $rest, $m, PREG_SET_ORDER)) {
            $last = end($m);
            $price = Money::parse(preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $last[1]));
            $rest = str_replace($last[0], ' ', $rest);
        }

        // Quantité et unité (« 120 m² », « 15 ml », « 2 u », « 3 x »).
        $quantity = null;
        $unit = null;
        $unitPattern = implode('|', array_map(fn ($u) => preg_quote($u, '/'), array_keys(self::UNITS)));
        if (preg_match('/(\d+(?:[.,]\d{1,3})?)\s*('.$unitPattern.')(?=[\s,.)]|$)/iu', $rest, $m)) {
            $quantity = str_replace(',', '.', $m[1]);
            $unit = self::UNITS[mb_strtolower($m[2])] ?? null;
            $rest = str_replace($m[0], ' ', $rest);
        } elseif (preg_match('/(?:^|\s)(?:x\s*(\d+(?:[.,]\d{1,3})?)|(\d+(?:[.,]\d{1,3})?)\s*x)(?=\s|$)/iu', $rest, $m)) {
            $quantity = str_replace(',', '.', $m[1] !== '' ? $m[1] : $m[2]);
            $unit = 'u';
            $rest = str_replace($m[0], ' ', $rest);
        } elseif (preg_match('/\b(forfait|ensemble)\b/iu', $rest, $m)) {
            $quantity = '1';
            $unit = 'forfait';
            $rest = str_replace($m[0], ' ', $rest);
        }

        // Désignation : ce qui reste, sans les petits mots de liaison.
        $designation = trim(preg_replace('/\s+/u', ' ', preg_replace('/(^|\s)(à|a|de|du|pour|au|le|la|les|:|,|x)(?=\s|$)/iu', ' ', $rest) ?? '') ?? '', " \t,.:-");
        $designation = Str::ucfirst($designation);

        // Prestation de la bibliothèque : le libellé, la description, l'unité et la TVA en viennent.
        $item = $this->catalogItem($designation);

        $defaultRate = (int) (VatRate::query()->where('is_default', true)->value('rate') ?? 0);
        $title = $item?->name ?? $designation;
        $unit ??= $item?->unit;
        $quantity ??= '1';
        $unit ??= 'forfait';
        $fromCatalog = $price === null && $item && $item->unit_price > 0;
        $price ??= $fromCatalog ? $item->unit_price : null;

        $blocking = false;
        if ($title === '') {
            $warnings[] = 'désignation manquante (« '.$segment.' »).';
            $blocking = true;
        }
        if ($price === null) {
            $warnings[] = 'prix manquant pour « '.($title ?: $segment).' » : ajoutez « à … € ».';
            $blocking = true;
        }
        if (Quantity::parse($quantity) === null) {
            $warnings[] = 'quantité illisible (« '.$segment.' »).';
            $blocking = true;
        }
        if ($fromCatalog) {
            $warnings[] = 'prix repris de votre bibliothèque ('.Money::format($price).').';
        }
        if (! $item && $title !== '') {
            $warnings[] = 'hors bibliothèque : ligne créée telle quelle.';
        }

        return [
            'source' => $segment,
            'title' => Str::limit($title, 255, ''),
            'description' => $item?->description,
            'quantity' => $quantity,
            'unit' => $unit,
            'unit_price' => $price !== null ? number_format($price / 100, 2, ',', '') : null,
            'unit_price_cents' => $price,
            'vat_rate' => $item?->vat_rate ?? $defaultRate,
            'catalog_item_id' => $item?->id,
            'recognized' => (bool) $item,
            'warnings' => $warnings,
            'blocking' => $blocking,
            'total' => $price !== null && Quantity::parse($quantity) !== null ? (int) round($price * Quantity::parse($quantity) / 1000) : null,
        ];
    }
}

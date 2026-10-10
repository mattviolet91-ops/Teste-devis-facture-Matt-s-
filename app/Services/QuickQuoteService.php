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
        $title = null;

        // « objet : … » : objet du devis, où qu'il soit.
        $segments = array_values(array_filter($segments, function (string $segment) use (&$title) {
            if (preg_match('/^(objet|titre)\s*[:\-]\s*(.+)$/iu', $segment, $m)) {
                $title = Str::ucfirst(trim($m[2]));

                return false;
            }

            return true;
        }));

        [$clientQuery, $client, $clients, $segments] = $this->extractClient($segments, $clientId);
        $lines = array_map(fn (string $segment) => $this->parseLine($segment), $segments);

        $errors = [];
        if (! $client) {
            $errors[] = $clients->isEmpty()
                ? 'Client introuvable : « '.$clientQuery.' ». Vérifiez le nom ou créez d\'abord sa fiche.'
                : 'Plusieurs clients correspondent à « '.$clientQuery.' » : choisissez le bon.';
        }
        if ($lines === []) {
            $errors[] = 'Aucune prestation trouvée : écrivez le client, puis chaque prestation séparée par une virgule, un tiret ou un retour à la ligne.';
        }
        foreach ($lines as $i => $line) {
            foreach ($line['problems'] as $problem) {
                $errors[] = 'Ligne '.($i + 1).' : '.$problem;
            }
        }

        $title ??= $lines ? Str::limit(collect($lines)->pluck('title')->filter()->take(2)->implode(' et '), 120, '') : null;

        return ['client' => $client, 'clients' => $clients, 'client_query' => $clientQuery, 'title' => $title ?: null, 'lines' => $lines, 'errors' => $errors];
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
    private const STOPWORDS = ['des', 'les', 'une', 'aux', 'sur', 'avec', 'dans', 'pour', 'toute', 'tout', 'complete', 'complet', 'chez', 'environ'];

    /** Mots de métier : aident à savoir où s'arrête le nom du client dans « Martin démoussage 120 m² ». */
    private const TRADE_WORDS = [
        'demoussage', 'nettoyage', 'pose', 'depose', 'remplacement', 'reparation', 'refection', 'renovation', 'traitement',
        'hydrofuge', 'faitage', 'faitiere', 'faitieres', 'gouttiere', 'gouttieres', 'zinguerie', 'evacuation', 'deplacement',
        'fourniture', 'couverture', 'toiture', 'isolation', 'velux', 'fenetre', 'chenau', 'cheneau', 'solin', 'solins',
        'etancheite', 'tuile', 'tuiles', 'ardoise', 'ardoises', 'bache', 'bachage', 'echafaudage', 'nacelle', 'forfait',
        'main', 'intervention', 'recherche', 'fuite', 'charpente', 'chevron', 'chevrons', 'liteaux', 'ecran', 'rive', 'rives',
        'noue', 'noues', 'descente', 'descentes', 'abergement', 'cheminee', 'mousse', 'peinture', 'demolition', 'mise',
    ];

    /** Mots autour du nom du client, ignorés pour le retrouver. */
    private const CLIENT_FILLERS = ['devis', 'pour', 'chez', 'client', 'cliente', 'a', 'de', 'du', 'et', 'm', 'mme', 'mlle', 'mr', 'mrs',
        'madame', 'monsieur', 'mademoiselle', 'messieurs', 'mesdames', 'famille', 'societe', 'ste', 'entreprise', 'faire', 'un', 'le', 'la'];

    /**
     * Mots importants d'un texte (sans accents, singulier).
     *
     * @return list<string>
     */
    private function keyTerms(string $text): array
    {
        $terms = [];
        foreach (preg_split('/[^\p{L}\d]+/u', Search::normalize($text)) ?: [] as $t) {
            $t = preg_replace('/s$/', '', $t) ?? $t;
            if (mb_strlen($t) >= 4 && ! in_array($t, self::STOPWORDS, true)) {
                $terms[] = $t;
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * Prestation de la bibliothèque. Jamais d'à-peu-près (un devis doit être juste) :
     * reprise seulement si tous les mots importants de son nom sont dans le texte, ou
     * si le texte est assez précis (au moins deux mots) pour ne désigner qu'elle.
     * Sinon, la ligne reste « hors bibliothèque » et la prestation proche est signalée.
     *
     * @return array{0: ?CatalogItem, 1: list<string>}
     */
    private function catalogItem(string $designation): array
    {
        $terms = $this->keyTerms($designation);
        if ($terms === []) {
            return [null, []];
        }

        // Même nom en double dans la bibliothèque : une seule fois.
        $items = CatalogItem::query()->active()->orderBy('id')->get()->unique(fn (CatalogItem $item) => Search::normalize($item->name));
        $candidates = $items->filter(function (CatalogItem $item) use ($terms) {
            $name = ' '.Search::normalize($item->name).' ';
            foreach ($terms as $term) {
                if (! str_contains($name, ' '.$term)) {
                    return false;
                }
            }

            return true;
        })->values();

        // Tous les mots du nom sont dans le texte : c'est elle (le plus long nom l'emporte).
        $exact = $items->filter(fn (CatalogItem $item) => ($name = $this->keyTerms($item->name)) !== [] && array_diff($name, $terms) === [])
            ->sortByDesc(fn (CatalogItem $item) => count($this->keyTerms($item->name)))->values();
        if ($exact->isNotEmpty() && ($exact->count() === 1 || count($this->keyTerms($exact[0]->name)) > count($this->keyTerms($exact[1]->name)))) {
            return [$exact->first(), []];
        }
        if ($candidates->count() === 1 && count($terms) >= 2) {
            // Le nom de la bibliothèque dit plus que le texte (« remplacement velux » →
            // « Remplacement d'un raccord Velux ») : reprise, mais signalée pour vérification.
            $item = $candidates->first();

            return [$item, ['reconnue comme « '.$item->name.' » (votre bibliothèque) : vérifiez que c\'est la bonne prestation.']];
        }
        if ($candidates->isEmpty()) {
            return [null, []];
        }

        $names = $candidates->sortBy(fn (CatalogItem $item) => mb_strlen($item->name))->take(3)->pluck('name')->implode(' », « ');

        return [null, ['proche de votre bibliothèque : « '.$names.' ». Écrivez son nom complet pour reprendre sa description et sa TVA.']];
    }

    /** @return list<string> */
    private function segments(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // « Faire un devis pour … », « Devis : … » en tête : ignorés.
        $text = preg_replace('/^\s*(?:(?:peux-tu|pouvez-vous|merci de)\s+)?(?:faire|fais|faites|créer|crée|créez|nouveau)?\s*(?:un\s+)?devis\s*(?:pour|chez|à|:)?\s*/iu', '', $text) ?? $text;
        // « M. Dupont », « Mme. Martin » : ce point-là ne sépare rien.
        $text = preg_replace('/(?<![\p{L}])(M|Mme|Mlle|Mr|Ste|St)\.\s*/u', '$1 ', $text) ?? $text;

        // Séparateurs : retour à la ligne, point-virgule, tiret entouré d'espaces, puce,
        // virgule ou point suivis d'un espace (« 12,5 » et « 12.5 » restent des nombres).
        $parts = preg_split('/\n+|;|\s+[—–-]\s+|\s*•\s*|,(?=\s)|\.(?=\s|$)/u', $text) ?: [];

        $segments = [];
        foreach ($parts as $part) {
            // « … 12 € et évacuation 150 € » : deux prestations (seulement s'il y a des chiffres des deux côtés).
            $pieces = preg_split('/\s+(?:et|puis|plus|ainsi que)\s+/iu', $part) ?: [$part];
            $current = array_shift($pieces);
            foreach ($pieces as $piece) {
                if (preg_match('/\d/', $current) && preg_match('/\d/', $piece)) {
                    $segments[] = $current;
                    $current = $piece;
                } else {
                    $current .= ' et '.$piece;
                }
            }
            $segments[] = $current;
        }

        return array_values(array_filter(array_map(fn ($p) => trim($p, " \t,.-—–:"), $segments), fn ($p) => $p !== ''));
    }

    /**
     * Le client est au début : seul (« Mme Martin, démoussage… ») ou collé à la
     * première prestation (« Mme Martin démoussage 120 m² à 12 € »).
     *
     * @param  list<string>  $segments
     * @return array{0: string, 1: ?Client, 2: Collection<int, Client>, 3: list<string>}
     */
    private function extractClient(array $segments, ?int $clientId): array
    {
        $first = (string) array_shift($segments);
        $chosen = $clientId ? Client::query()->find($clientId) : null;

        $query = $first;
        if (preg_match('/\d/', $first)) {
            $words = preg_split('/\s+/u', $first) ?: [];
            $firstDigit = collect($words)->search(fn ($w) => (bool) preg_match('/\d/', $w));
            $split = null;
            $fallback = null;

            // Le plus long début de phrase (sans chiffre) qui désigne le client.
            for ($n = min(6, (int) $firstDigit); $n >= 1; $n--) {
                $matches = $this->lookupClients(implode(' ', array_slice($words, 0, $n)), false);
                if ($chosen ? $matches->contains('id', $chosen->id) : $matches->count() === 1) {
                    $split = $n;
                    break;
                }
                if ($fallback === null && $matches->isNotEmpty()) {
                    $fallback = $n;
                }
            }
            // Client inconnu : le nom s'arrête au premier mot de métier (ou au premier chiffre).
            if ($split === null && $fallback === null) {
                $trade = collect($words)->search(fn ($w) => in_array(preg_replace('/s$/', '', Search::normalize($w)), array_map(fn ($t) => preg_replace('/s$/', '', $t), self::TRADE_WORDS), true));
                $fallback = $trade !== false && $trade < $firstDigit ? (int) $trade : (int) $firstDigit;
            }
            $split ??= $fallback;

            $query = implode(' ', array_slice($words, 0, $split));
            $remainder = trim(implode(' ', array_slice($words, $split)));
            if ($remainder !== '') {
                array_unshift($segments, $remainder);
            }
        } else {
            // « Mme Martin, Massy, démoussage… » : la ville qui suit précise le client.
            while ($segments !== [] && ! preg_match('/\d/', $segments[0])) {
                $wider = $this->lookupClients($query.' '.$segments[0], false);
                if ($wider->isEmpty()) {
                    break;
                }
                $query .= ' '.array_shift($segments);
            }
        }

        if ($chosen) {
            return [$query, $chosen, collect([$chosen]), $segments];
        }

        $clients = $this->lookupClients($query, true);

        return [$query, $clients->count() === 1 ? $clients->first() : null, $clients, $segments];
    }

    /** @return Collection<int, Client> */
    private function lookupClients(string $query, bool $loose): Collection
    {
        $terms = array_values(array_filter(Search::terms($query), fn ($t) => ! in_array($t, self::CLIENT_FILLERS, true)));
        if ($terms === []) {
            return collect();
        }

        $matches = Client::query()->search(implode(' ', $terms))->limit(10)->get();
        // Rien avec tous les mots (ville inconnue, faute…) : un mot qui est exactement le nom.
        if ($matches->isEmpty() && $loose) {
            $matches = collect($terms)->filter(fn ($t) => mb_strlen($t) >= 3)
                ->flatMap(fn ($t) => Client::query()->search($t)->limit(10)->get())
                ->unique('id')
                ->filter(fn (Client $c) => array_intersect($terms, Search::terms($c->last_name.' '.$c->company_name)) !== [])
                ->values();
        }

        // Plusieurs : le nom exact l'emporte (« Martin » ≠ « Martinez »), puis la ville.
        if ($matches->count() > 1) {
            $exact = $matches->filter(fn (Client $c) => array_intersect($terms, Search::terms($c->last_name.' '.$c->company_name)) !== [])->values();
            if ($exact->isNotEmpty()) {
                $matches = $exact;
            }
            if ($matches->count() > 1) {
                $inCity = $matches->filter(fn (Client $c) => $c->city && array_intersect($terms, Search::terms($c->city)) !== [])->values();
                if ($inCity->isNotEmpty()) {
                    $matches = $inCity;
                }
            }
        }

        return $matches->take(6)->values();
    }

    /** @return array<string, mixed> */
    private function parseLine(string $segment): array
    {
        $L = '(?<![\p{L}\d])';
        $R = '(?![\p{L}\d])';
        $num = '(\d+(?:[.,]\d{1,3})?)';
        $s = ' '.$segment.' ';
        $warnings = [];

        // Unités écrites en toutes lettres → m², ml.
        $s = preg_replace('/'.$L.'m(?:è|e)tres?\s+carr(?:é|e)s?'.$R.'/iu', 'm²', $s) ?? $s;
        $s = preg_replace('/'.$L.'m(?:è|e)tres?\s+lin(?:é|e)aires?'.$R.'/iu', 'ml', $s) ?? $s;
        $s = preg_replace('/(?<![\p{L}])m2'.$R.'/iu', 'm²', $s) ?? $s;
        $s = preg_replace('/(\d)(m²|ml)/u', '$1 $2', $s) ?? $s;

        // HT / TTC.
        if (preg_match('/'.$L.'ttc'.$R.'/iu', $s)) {
            $warnings[] = 'prix indiqué TTC : les devis sont établis HT, vérifiez le montant.';
        }
        $s = preg_replace('/'.$L.'(?:ht|h\.t\.|ttc|t\.t\.c\.|hors taxes?)'.$R.'/iu', ' ', $s) ?? $s;

        // « … € le m² », « /ml », « de l'heure », « l'unité » : l'unité du prix, pas une quantité.
        $priceUnit = null;
        if (preg_match('/(?:\/\s*|(?<=€)\s*|'.$L.'(?:le|la|du|par|au|pour|de\s+l\'|l\'|chaque)\s+|'.$L.'l\')(m²|ml|m|mètre|metre|unité|unite|u|pièce|piece|heure|h)'.$R.'/iu', $s, $m)) {
            $priceUnit = match (mb_strtolower($m[1])) {
                'm²' => 'm²', 'ml', 'm', 'mètre', 'metre' => 'ml', 'heure', 'h' => 'h', default => 'u',
            };
            $s = str_replace($m[0], ' ', $s);
        }

        // Quantité : « x3 », « 3 x », « 3 fois » d'abord (sinon « x3 150 € » se lirait 3 150 €).
        $quantity = null;
        $unit = null;
        if (preg_match('/'.$L.'(?:[x×]\s*'.$num.'(?!\s*(?:€|eur))|'.$num.'\s*(?:[x×]|fois))'.$R.'/iu', $s, $m)) {
            $quantity = $m[1] !== '' ? $m[1] : $m[2];
            $s = str_replace($m[0], ' ', $s);
        }
        $units = 'm²|ml|u|h|unités?|unites?|pièces?|pieces?|pcs|heures?|forfaits?|ens';
        if (preg_match('/'.$L.'(\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+(?:[.,]\d{1,3})?|\d+(?:[.,]\d{1,3})?)\s*('.$units.')'.$R.'/iu', $s, $m)) {
            if ($quantity === null) {
                $quantity = preg_replace('/[ \x{00A0}\x{202F}]/u', '', $m[1]);
                $unit = $this->unitCode($m[2]);
                $s = str_replace($m[0], ' ', $s);
            }
        }

        // Prix : le dernier montant suivi de € (« 1 500 € », « 12,50€ »), sinon « à 12 » en fin de ligne.
        $price = null;
        $amount = '(\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+|\d+)(?:[.,](\d{1,2}))?';
        if (preg_match_all('/'.$L.$amount.'\s*(?:€|eur(?:os?)?'.$R.')/iu', $s, $all, PREG_SET_ORDER)) {
            $m = end($all);
            $price = Money::parse(preg_replace('/\D/u', '', $m[1]).(isset($m[2]) && $m[2] !== '' ? ','.$m[2] : ''));
            $s = str_replace($m[0], ' ', $s);
        } elseif (preg_match('/'.$L.'(?:à|a|pour|au prix de|prix)\s+'.$amount.'\s*$/iu', rtrim($s), $m)) {
            $price = Money::parse(preg_replace('/\D/u', '', $m[1]).(isset($m[2]) && $m[2] !== '' ? ','.$m[2] : ''));
            $s = str_replace($m[0], ' ', rtrim($s)).' ';
        }

        // Un seul nombre restant devant un mot : « 3 faîtières », « pose de 2 velux ».
        $bare = null;
        if ($quantity === null && preg_match_all('/'.$L.$num.$R.'/u', $s, $all, PREG_SET_ORDER) === 1) {
            $quantity = $bare = $all[0][1];
            $s = preg_replace('/'.$L.preg_quote($bare, '/').$R.'/u', "\u{1}", $s, 1) ?? $s;
        }

        // « forfait » : quantité 1, unité forfait.
        if (preg_match('/'.$L.'(?:au\s+|en\s+)?(forfaits?|ensemble)'.$R.'/iu', $s, $m)) {
            // « pose de 2 velux forfait 1 800 € » : un forfait de 1 800 € pour les deux,
            // pas 2 × 1 800 € ; le nombre reste dans la désignation.
            if ($unit === null && $bare !== null) {
                $s = str_replace("\u{1}", $bare, $s);
                $quantity = $bare = null;
            }
            if ($unit === null && ($quantity === null || $quantity === '1')) {
                $unit = 'forfait';
            }
            $s = str_replace($m[0], ' ', $s);
        }
        $s = str_replace("\u{1}", ' ', $s);

        // Désignation : ce qui reste, sans les petits mots de liaison aux extrémités.
        $designation = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        $edge = '(?:à|a|de|d\'|du|des|pour|au|aux|le|la|les|l\'|x|et|puis|plus|en|soit|:|,|\(|\)|-)';
        do {
            $before = $designation;
            $designation = trim(preg_replace('/^'.$edge.'(?:\s+|(?<=\')|$)|(?:^|\s)'.$edge.'$/iu', '', $designation) ?? '', " \t,.:;-");
        } while ($designation !== $before && $designation !== '');
        $designation = Str::ucfirst($designation);

        // Prestation de la bibliothèque : libellé, description, unité et TVA en viennent.
        [$item, $hints] = $this->catalogItem($designation);
        $warnings = array_merge($warnings, $hints);

        $defaultRate = (int) (VatRate::query()->where('is_default', true)->value('rate') ?? 0);
        // Le texte dit plus que le nom de la bibliothèque (« … côté rue ») : on garde ses mots.
        $extra = $item ? array_diff($this->keyTerms($designation), $this->keyTerms($item->name)) : [];
        $title = $item && $extra === [] ? $item->name : $designation;
        $unit ??= $priceUnit ?? $item?->unit ?? ($quantity !== null ? 'u' : 'forfait');
        $quantity = str_replace(',', '.', $quantity ?? '1');
        $fromCatalog = $price === null && $item && $item->unit_price > 0;
        $price ??= $fromCatalog ? $item->unit_price : null;

        $problems = [];
        if ($title === '') {
            $problems[] = 'désignation manquante (« '.$segment.' »).';
        }
        if ($price === null) {
            $problems[] = 'prix manquant pour « '.($title ?: $segment).' » : ajoutez « à … € ».';
        }
        if (Quantity::parse($quantity) === null) {
            $problems[] = 'quantité illisible (« '.$segment.' »).';
        }
        if ($fromCatalog) {
            $warnings[] = 'prix repris de votre bibliothèque ('.Money::format($price).').';
        }
        if (! $item && $title !== '' && $hints === []) {
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
            // Problèmes (bloquants) d'abord, puis simples remarques.
            'warnings' => array_merge($problems, $warnings),
            'problems' => $problems,
            'blocking' => $problems !== [],
            'total' => $price !== null && Quantity::parse($quantity) !== null ? (int) round($price * Quantity::parse($quantity) / 1000) : null,
        ];
    }

    private function unitCode(string $word): string
    {
        $word = mb_strtolower($word);

        return match (true) {
            $word === 'm²' => 'm²',
            $word === 'ml' => 'ml',
            $word === 'h' || str_starts_with($word, 'heure') => 'h',
            str_starts_with($word, 'forfait') || $word === 'ens' => 'forfait',
            default => 'u',
        };
    }
}

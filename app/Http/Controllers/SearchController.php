<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Worksite;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Recherche globale. Les devis et factures s'y ajouteront aux phases 6 et 7.
 */
class SearchController extends Controller
{
    private const LIMIT = 15;

    public function __invoke(Request $request): View
    {
        $q = mb_substr(trim((string) $request->query('q')), 0, 100);
        $results = ['clients' => collect(), 'worksites' => collect()];

        if (Search::terms($q) !== []) {
            $results['clients'] = Client::query()->searchWithWorksites($q)->alphabetical()->limit(self::LIMIT)->get();
            $results['worksites'] = Worksite::query()->search($q)->whereHas('client')->with('client')->limit(self::LIMIT)->get();
        }

        $view = $request->boolean('partial') ? 'search.results' : 'search.index';

        return view($view, compact('q', 'results'));
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/** Écran provisoire des modules pas encore livrés. */
class ComingSoonController extends Controller
{
    public const MODULES = [
        'devis' => ['Devis', 6],
        'factures' => ['Factures', 7],
        'paiements' => ['Paiements', 10],
        'photos' => ['Photos', 9],
        'prestations' => ['Bibliothèque de prestations', 6],
    ];

    public function __invoke(string $module): View
    {
        abort_unless(isset(self::MODULES[$module]), 404);

        [$title, $phase] = self::MODULES[$module];

        return view('coming-soon', compact('title', 'phase'));
    }
}

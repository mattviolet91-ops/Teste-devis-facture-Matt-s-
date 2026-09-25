<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Settings;
use App\Support\Navigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Personnalisation : raccourcis de la barre du bas et blocs de la page d'accueil. */
class DisplayController extends Controller
{
    public function edit(): View
    {
        $home = Navigation::home();
        // Blocs affichés d'abord (dans l'ordre choisi), puis les autres.
        $blocks = collect($home)->merge(array_diff(array_keys(Navigation::HOME_BLOCKS), $home))->values();

        return view('settings.display', [
            'bottom' => Navigation::bottom(),
            'blocks' => $blocks,
            'enabled' => $home,
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'bottom' => ['required', 'array', 'size:3'],
            'bottom.*' => ['required', 'distinct', Rule::in(array_keys(Navigation::ITEMS))],
            'order' => ['required', 'array'],
            'order.*' => [Rule::in(array_keys(Navigation::HOME_BLOCKS))],
            'blocks' => ['nullable', 'array'],
            'blocks.*' => [Rule::in(array_keys(Navigation::HOME_BLOCKS))],
        ], [
            'bottom.*.distinct' => 'Choisissez 3 raccourcis différents.',
        ]);

        $enabled = array_values(array_filter($data['order'], fn ($key) => in_array($key, $data['blocks'] ?? [], true)));
        if (! $enabled) {
            return back()->withErrors(['blocks' => 'Gardez au moins un bloc sur la page d\'accueil.'])->withInput();
        }

        $settings->set([
            'layout.bottom_nav' => array_values($data['bottom']),
            'layout.home_blocks' => $enabled,
        ]);

        return back()->with('status', 'Affichage enregistré.');
    }

    public function reset(Settings $settings): RedirectResponse
    {
        $settings->set(['layout.bottom_nav' => Navigation::DEFAULT_BOTTOM, 'layout.home_blocks' => Navigation::DEFAULT_HOME]);

        return back()->with('status', 'Affichage remis par défaut.');
    }
}

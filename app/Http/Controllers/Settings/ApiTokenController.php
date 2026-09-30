<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Réglages → Accès Claude : clés permettant à Claude de créer des devis brouillons. */
class ApiTokenController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('settings.api', [
            'tokens' => ApiToken::query()->with('user')->latest('id')->get(),
            'newToken' => session('new_api_token'),
            'apiUrl' => rtrim((string) config('app.url'), '/').'/api/v1',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:80']], [], ['name' => 'nom de la clé']);

        $plain = ApiToken::issue($request->user(), $data['name']);
        ActivityLogger::log('api.token.created', 'Clé d\'accès créée : '.$data['name']);

        // Affichée une seule fois : elle n'est enregistrée que sous forme d'empreinte.
        return redirect()->route('settings.api')->with('new_api_token', $plain)
            ->with('status', 'Clé créée. Copiez-la maintenant : elle ne sera plus jamais affichée.');
    }

    public function destroy(Request $request, ApiToken $token): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $token->delete();
        ActivityLogger::log('api.token.revoked', 'Clé d\'accès révoquée : '.$token->name);

        return redirect()->route('settings.api')->with('status', 'Clé révoquée : elle ne fonctionne plus.');
    }
}

<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Réglages → Accès Claude : clés pour Claude (devis brouillons) et pour l'app Argent (lecture seule). */
class ApiTokenController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('settings.api', [
            'tokens' => ApiToken::query()->with('user')->latest('id')->get(),
            'newToken' => session('new_api_token'),
            'newTokenScope' => session('new_api_token_scope', 'claude'),
            'apiUrl' => rtrim((string) config('app.url'), '/').'/api/v1',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'scope' => ['nullable', 'in:'.implode(',', array_keys(ApiToken::SCOPES))],
        ], [], ['name' => 'nom de la clé']);
        $scope = $data['scope'] ?? 'claude';

        $plain = ApiToken::issue($request->user(), $data['name'], $scope);
        ActivityLogger::log('api.token.created', 'Clé d\'accès créée : '.$data['name'].' ('.ApiToken::SCOPES[$scope].')');

        // Affichée une seule fois : elle n'est enregistrée que sous forme d'empreinte.
        return redirect()->route('settings.api')->with('new_api_token', $plain)->with('new_api_token_scope', $scope)
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

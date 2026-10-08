<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * API : clé « Bearer » obligatoire, compte gérant actif, et jamais depuis
 * l'adresse réservée aux clients. Paramètre : portée exigée (une clé Claude
 * n'ouvre pas la partie Argent, et inversement).
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, string $scope = 'claude'): Response
    {
        // Réponses et erreurs toujours en JSON.
        $request->headers->set('Accept', 'application/json');
        $clientHost = parse_url((string) config('entreprise.client_url'), PHP_URL_HOST);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($clientHost && $clientHost !== $appHost && strcasecmp($request->getHost(), $clientHost) === 0) {
            abort(404);
        }

        $plain = (string) $request->bearerToken();
        $token = $plain !== '' ? ApiToken::findByPlain($plain) : null;
        $user = $token?->user;
        if (! $token || ! $user || $user->disabled_at !== null || ! $user->isAdmin()) {
            return response()->json(['message' => 'Clé d\'accès invalide ou révoquée.'], 401);
        }

        if (($token->scope ?: 'claude') !== $scope) {
            return response()->json(['message' => 'Cette clé ne donne pas accès à cette partie.'], 403);
        }
        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }
        Auth::setUser($user);

        return $next($request);
    }
}

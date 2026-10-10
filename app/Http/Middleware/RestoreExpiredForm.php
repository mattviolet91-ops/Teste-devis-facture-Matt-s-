<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Formulaire envoyé après expiration de la session (page restée ouverte trop longtemps) :
 * sa saisie a été gardée (voir bootstrap/app.php). Au retour sur la page — directement,
 * ou après s'être reconnecté —, le formulaire est ré-affiché rempli, rien n'est perdu.
 */
class RestoreExpiredForm
{
    public const KEY = 'expired_form';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->hasSession() && ($saved = $request->session()->get(self::KEY))) {
            if (($saved['at'] ?? 0) < now()->subDay()->timestamp) {
                $request->session()->forget(self::KEY);
            } elseif ($request->user() && ($saved['url'] ?? null) === $request->fullUrl()) {
                $request->session()->forget(self::KEY);
                $request->session()->now('_old_input', $saved['input'] ?? []);
                $request->session()->now('status', 'La page était restée ouverte trop longtemps : rien n\'est perdu. Vérifiez vos informations puis enregistrez de nouveau.');
            } elseif (! $request->user() && $request->routeIs('login')) {
                $request->session()->now('status', 'Votre session a expiré : reconnectez-vous. Ce que vous aviez saisi est gardé et vous sera ré-affiché.');
            }
        }

        return $next($request);
    }
}

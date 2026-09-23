<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adresse réservée aux clients (ex. devis.matts-couverture.fr) : seules les
 * pages du lien client y sont accessibles, jamais l'espace de gestion.
 */
class ClientHostOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $clientHost = parse_url((string) config('entreprise.client_url'), PHP_URL_HOST);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! $clientHost || $clientHost === $appHost || strcasecmp($request->getHost(), $clientHost) !== 0) {
            return $next($request);
        }

        if (str_starts_with((string) $request->route()?->getName(), 'portal.')) {
            return $next($request);
        }

        // Page d'accueil de l'adresse client : renvoi vers le site de l'entreprise.
        if ($request->path() === '/' && ($website = config('entreprise.company.website'))) {
            return redirect()->away($website);
        }

        abort(404);
    }
}

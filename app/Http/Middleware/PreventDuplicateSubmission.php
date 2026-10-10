<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Réseau mobile instable : un même formulaire peut arriver deux fois (double appui,
 * réponse perdue puis renvoi, envoi différé rejoué). Chaque formulaire porte un
 * identifiant unique « _once » (ajouté par public/js/app.js) : le second envoi n'est
 * pas traité une deuxième fois, il renvoie vers le résultat du premier.
 */
class PreventDuplicateSubmission
{
    /** Durée pendant laquelle un envoi est reconnu (envois différés compris). */
    private const TTL = 172800;

    /** Attente maximale du premier envoi encore en cours (secondes). */
    private const WAIT = 20;

    public function handle(Request $request, Closure $next): Response
    {
        $once = $request->input('_once');
        if (! $request->isMethod('POST') || ! is_string($once) || ! preg_match('/^[A-Za-z0-9-]{16,64}$/', $once)) {
            return $next($request);
        }
        // Jamais transmis aux contrôleurs (ni enregistré par erreur).
        $request->request->remove('_once');

        // Compte connecté (stable même si la session change), sinon la session du visiteur.
        $owner = $request->user()?->getAuthIdentifier() ? 'u'.$request->user()->getAuthIdentifier() : 's'.$request->session()->getId();
        // Seul un envoi au contenu identique est un doublon. Même identifiant mais contenu
        // différent (formulaire retrouvé par un retour arrière puis modifié) : nouvel envoi,
        // traité normalement — sinon la saisie serait perdue.
        $key = 'once:'.hash('sha256', $owner.'|'.$request->path().'|'.$once.'|'.$this->fingerprint($request));
        if (! Cache::add($key, ['state' => 'pending'], self::TTL)) {
            return $this->replay($request, $key);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            Cache::forget($key);

            throw $e;
        }

        // Erreurs de saisie renvoyées par CETTE demande (pas celles d'un envoi précédent).
        $failed = $response->getStatusCode() >= 400 || in_array('errors', (array) $request->session()->get('_flash.new', []), true);
        if (! $failed && $response instanceof RedirectResponse) {
            Cache::put($key, ['state' => 'done', 'url' => $response->getTargetUrl()], self::TTL);
        } elseif (! $failed && $response instanceof JsonResponse) {
            Cache::put($key, ['state' => 'done', 'json' => $response->getData(true), 'status' => $response->getStatusCode()], self::TTL);
        } else {
            // Erreur à corriger ou simple affichage : le même formulaire pourra être renvoyé.
            Cache::forget($key);
        }

        return $response;
    }

    /** Empreinte du contenu envoyé (champs et fichiers joints), hors jeton de sécurité. */
    private function fingerprint(Request $request): string
    {
        $files = array_map(
            fn ($file) => $file instanceof UploadedFile ? [$file->getClientOriginalName(), $file->getSize()] : null,
            Arr::flatten($request->allFiles()),
        );

        return hash('sha256', serialize([Arr::except($request->request->all(), ['_token']), $files]));
    }

    /** Second envoi du même formulaire : résultat du premier, sans rien refaire. */
    private function replay(Request $request, string $key): Response
    {
        $deadline = microtime(true) + self::WAIT;
        $entry = Cache::get($key);
        while (($entry['state'] ?? null) === 'pending' && microtime(true) < $deadline) {
            usleep(300000);
            $entry = Cache::get($key);
        }

        if (isset($entry['json'])) {
            return response()->json($entry['json'], $entry['status'] ?? 200);
        }
        if (isset($entry['url'])) {
            return redirect()->to($entry['url'])->with('status', 'Déjà enregistré : ce formulaire était arrivé deux fois, il n\'a été pris en compte qu\'une seule fois.');
        }
        // Premier envoi pas encore terminé (ou effacé entre-temps) : ne rien refaire.
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Envoi déjà en cours.'], 409);
        }

        return redirect()->back()->with('status', 'Envoi déjà en cours : patientez quelques secondes puis vérifiez avant de renvoyer.');
    }
}

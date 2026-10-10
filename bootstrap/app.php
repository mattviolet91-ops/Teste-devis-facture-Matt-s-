<?php

use App\Http\Middleware\ClientHostOnly;
use App\Http\Middleware\PreventDuplicateSubmission;
use App\Http\Middleware\RestoreExpiredForm;
use App\Http\Middleware\RestrictByRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [ClientHostOnly::class, RestrictByRole::class, PreventDuplicateSubmission::class, RestoreExpiredForm::class]);
        // Avant l'authentification : l'adresse client ne renvoie jamais vers la connexion.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: ClientHostOnly::class,
        );
        // Appels venant de myPOS : pas de jeton CSRF (la notification est vérifiée par signature).
        $middleware->validateCsrfTokens(except: ['mypos/notification', 'stats/collect', 'f/*/paiement-ok', 'f/*/paiement-annule']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Page restée ouverte trop longtemps (session expirée, jeton de sécurité périmé) :
        // au lieu de perdre la saisie (un devis entier…), on la garde et on revient au
        // formulaire, qui sera ré-affiché rempli (RestoreExpiredForm).
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 419 || $request->getRealMethod() !== 'POST' || $request->expectsJson() || ! $request->hasSession()) {
                return null;
            }
            $back = (string) $request->headers->get('referer');
            if ($back === '' || parse_url($back, PHP_URL_HOST) !== $request->getHost()) {
                return null;
            }
            if (parse_url($back, PHP_URL_PATH) === parse_url(route('login'), PHP_URL_PATH)) {
                return redirect()->route('login')->with('status', 'La page de connexion était restée ouverte trop longtemps : reconnectez-vous.');
            }

            $request->session()->put(RestoreExpiredForm::KEY, [
                'url' => $back,
                'input' => collect($request->request->all())->except(['_token', '_once', '_method', 'password', 'password_confirmation', 'current_password'])->all(),
                'at' => now()->timestamp,
            ]);

            return redirect()->to($back);
        });
    })->create();

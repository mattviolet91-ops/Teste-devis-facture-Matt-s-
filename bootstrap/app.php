<?php

use App\Http\Middleware\ClientHostOnly;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: ClientHostOnly::class);
        // Avant l'authentification : l'adresse client ne renvoie jamais vers la connexion.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: ClientHostOnly::class,
        );
        // Appels venant de myPOS : pas de jeton CSRF (la notification est vérifiée par signature).
        $middleware->validateCsrfTokens(except: ['mypos/notification', 'f/*/paiement-ok', 'f/*/paiement-annule']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

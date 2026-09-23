<?php

namespace App\Providers;

use App\Services\MailSettings;
use App\Services\Settings;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Settings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('*', function ($view) {
            $view->with('settings', app(Settings::class));
        });

        // Gmail configuré dans Réglages → Emails : utilisé pour tous les envois
        // (documents, mot de passe oublié…). Ignoré tant que la base n'existe pas.
        try {
            app(MailSettings::class)->apply();
        } catch (\Throwable) {
            // Base indisponible (installation en cours) : configuration du .env.
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Services\WeatherService;
use Illuminate\Console\Command;

/** Télécharge la météo des chantiers et rendez-vous des prochains jours (affichée dans le planning). */
class RefreshWeather extends Command
{
    protected $signature = 'app:weather';

    protected $description = 'Met à jour la météo des chantiers et rendez-vous à venir';

    public function handle(WeatherService $weather): int
    {
        $this->info($weather->warm().' intervention(s) avec météo.');

        return self::SUCCESS;
    }
}

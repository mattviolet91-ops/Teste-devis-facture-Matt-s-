<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    public function up(): void
    {
        // Accueil personnalisé : le bloc « Aujourd'hui » est ajouté en premier.
        $setting = Setting::query()->where('key', 'layout.home_blocks')->first();
        if ($setting && is_array($setting->value) && ! in_array('today', $setting->value, true)) {
            $setting->value = array_merge(['today'], $setting->value);
            $setting->save();
            Cache::forget('settings.all');
        }
    }

    public function down(): void
    {
        // Rien : l'accueil reste modifiable dans Réglages → Mon affichage.
    }
};

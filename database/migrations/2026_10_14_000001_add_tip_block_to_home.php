<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    public function up(): void
    {
        // Accueil personnalisé : l'« Astuce du jour » est ajoutée en dernier.
        $setting = Setting::query()->where('key', 'layout.home_blocks')->first();
        if ($setting && is_array($setting->value) && ! in_array('tip', $setting->value, true)) {
            $setting->value = array_merge($setting->value, ['tip']);
            $setting->save();
            Cache::forget('settings.all');
        }
    }

    public function down(): void
    {
        // Rien : l'accueil reste modifiable dans Réglages → Mon affichage.
    }
};

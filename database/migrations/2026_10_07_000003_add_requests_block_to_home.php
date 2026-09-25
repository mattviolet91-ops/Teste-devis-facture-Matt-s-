<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Accueil déjà personnalisé : le bloc « Demandes de devis » est ajouté en tête.
        $setting = Setting::query()->where('key', 'layout.home_blocks')->first();
        if ($setting && is_array($setting->value) && ! in_array('requests', $setting->value, true)) {
            $setting->value = array_merge(['requests'], $setting->value);
            $setting->save();
        }
    }

    public function down(): void
    {
        // Rien : modifiable dans Réglages → Mon affichage.
    }
};

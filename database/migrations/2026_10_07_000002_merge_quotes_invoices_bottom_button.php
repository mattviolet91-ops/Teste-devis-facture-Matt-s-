<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Barre du bas : le bouton « Devis » devient « Documents » (devis et factures réunis).
        $setting = Setting::query()->where('key', 'layout.bottom_nav')->first();
        if ($setting && is_array($setting->value) && in_array('devis', $setting->value, true) && ! in_array('documents', $setting->value, true)) {
            $setting->value = array_map(fn ($key) => $key === 'devis' ? 'documents' : $key, $setting->value);
            $setting->save();
        }
    }

    public function down(): void
    {
        // Rien : le réglage reste modifiable dans Réglages → Mon affichage.
    }
};

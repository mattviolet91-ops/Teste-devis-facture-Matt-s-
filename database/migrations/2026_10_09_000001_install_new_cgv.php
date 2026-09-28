<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Nouvelles conditions générales de vente validées le 28/09/2026 : elles
 * remplacent le texte enregistré, l'ancien est gardé dans « pdf.cgv_previous ».
 */
return new class extends Migration
{
    public function up(): void
    {
        $new = config('entreprise.pdf.cgv');
        $current = Setting::query()->where('key', 'pdf.cgv')->first();

        if ($current) {
            if ($current->value !== $new && ! Setting::query()->where('key', 'pdf.cgv_previous')->exists()) {
                Setting::query()->create(['key' => 'pdf.cgv_previous', 'value' => $current->value]);
            }
            $current->value = $new;
            $current->save();
        }

        Cache::forget('settings.all');
    }

    public function down(): void
    {
        // Rien : le texte reste modifiable dans Réglages → Documents PDF.
    }
};

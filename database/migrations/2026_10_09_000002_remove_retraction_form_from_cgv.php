<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Le formulaire de rétractation est retiré des CGV (décision du 28/09/2026) :
 * seuls la ligne du formulaire et le renvoi vers lui sont enlevés, le reste du texte ne change pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        $setting = Setting::query()->where('key', 'pdf.cgv')->first();
        if (! $setting || ! is_string($setting->value)) {
            return;
        }

        $lines = array_filter(preg_split('/\R/', $setting->value), fn ($line) => ! str_starts_with(trim($line), 'Formulaire de rétractation'));
        $text = str_replace(' ou au moyen du formulaire ci-dessous', '', implode("\n", $lines));

        if ($text !== $setting->value) {
            $setting->value = $text;
            $setting->save();
            Cache::forget('settings.all');
        }
    }

    public function down(): void
    {
        // Rien : le texte reste modifiable dans Réglages → Documents PDF.
    }
};

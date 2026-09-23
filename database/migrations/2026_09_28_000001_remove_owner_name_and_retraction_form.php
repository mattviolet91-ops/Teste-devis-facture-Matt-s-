<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Le nom de l'entrepreneur n'apparaît plus nulle part et le formulaire de
 * rétractation est retiré de l'application (décision du 23/09/2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->whereIn('key', ['company.owner_name', 'pdf.retraction_form'])->delete();

        // CGV déjà enregistrées : on retire le paragraphe qui annonçait le formulaire.
        $cgv = DB::table('settings')->where('key', 'pdf.cgv')->value('value');
        if ($cgv !== null) {
            $text = json_decode($cgv, true);
            if (is_string($text) && str_contains($text, 'formulaire de rétractation')) {
                $lines = array_filter(preg_split('/\R/', $text), fn ($line) => ! str_contains($line, 'formulaire de rétractation'));
                DB::table('settings')->where('key', 'pdf.cgv')->update(['value' => json_encode(implode("\n", $lines))]);
            }
        }

        Cache::forget('settings.all');
    }

    public function down(): void
    {
        // Rien à restaurer.
    }
};

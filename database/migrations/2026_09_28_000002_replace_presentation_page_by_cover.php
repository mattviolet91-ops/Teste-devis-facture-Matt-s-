<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** La page de présentation est remplacée par la page de couverture. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'pdf.presentation_enabled')->delete();
        Cache::forget('settings.all');
    }

    public function down(): void
    {
        // Rien à restaurer.
    }
};

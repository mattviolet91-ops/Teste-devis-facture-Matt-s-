<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'espace Argent est devenu une application séparée (app Argent) : ses tables
 * et réglages sont retirés de l'app de devis.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['money_weekly_reports', 'money_rules', 'money_goals', 'money_transactions', 'money_recurrings', 'money_categories', 'money_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('settings')->where('key', 'like', 'argent.%')->delete();
        DB::table('migrations')->where('migration', '2026_10_16_000001_create_money_tables')->delete();
    }

    public function down(): void
    {
        // Rien à recréer : l'app Argent séparée garde ses propres données.
    }
};

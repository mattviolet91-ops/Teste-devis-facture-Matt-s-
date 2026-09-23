<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** La mention « Le client atteste… » n'est plus imprimée (demande du 23/09/2026). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => 'vat.reduced_rate_mention_enabled'],
            ['value' => json_encode(false), 'created_at' => now(), 'updated_at' => now()],
        );
        Cache::forget('settings.all');
    }

    public function down(): void
    {
        // Rien à restaurer.
    }
};

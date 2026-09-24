<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Devis signé sur le téléphone de l'artisan, pendant le rendez-vous.
        Schema::table('quotes', function (Blueprint $table) {
            $table->boolean('signed_on_site')->default(false)->after('signed_user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('signed_on_site');
        });
    }
};

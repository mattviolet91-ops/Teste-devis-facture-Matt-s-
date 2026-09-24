<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le planning accueille aussi les rendez-vous (visite pour devis, fournisseur…).
        Schema::table('interventions', function (Blueprint $table) {
            $table->string('kind', 10)->default('chantier')->after('id')->index();
            $table->string('end_time', 5)->nullable()->after('start_time');
            $table->string('location', 200)->nullable()->after('worksite_id');
            $table->timestamp('reminded_at')->nullable()->after('notes');
        });
        // Un rendez-vous peut ne concerner aucun client (fournisseur, comptable…).
        Schema::table('interventions', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropColumn(['kind', 'end_time', 'location', 'reminded_at']);
        });
    }
};

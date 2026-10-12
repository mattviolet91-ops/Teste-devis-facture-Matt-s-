<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réparation urgente demandée par le client à son domicile : pas de délai de 7 jours
 * avant un paiement (art. L221-10 du Code de la consommation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->boolean('urgent_repair')->default(false)->after('signed_on_site');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', fn (Blueprint $table) => $table->dropColumn('urgent_repair'));
    }
};

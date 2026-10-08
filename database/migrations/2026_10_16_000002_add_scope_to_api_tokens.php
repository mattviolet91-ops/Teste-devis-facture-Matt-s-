<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Portée d'une clé : « claude » (devis brouillons) ou « argent » (lecture des paiements et frais par l'app Argent). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->string('scope', 20)->default('claude')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('api_tokens', fn (Blueprint $table) => $table->dropColumn('scope'));
    }
};

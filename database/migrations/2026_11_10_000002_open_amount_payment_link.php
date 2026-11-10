<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lien permanent « montant libre » : le client indique lui-même son nom, le motif et le montant.
 * La fiche client n'est retrouvée (par l'email) ou créée qu'une fois le paiement confirmé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('client_id')->nullable()->change();
            $table->string('payer_first_name', 80)->nullable()->after('client_id');
            $table->string('payer_last_name', 80)->nullable()->after('payer_first_name');
            $table->string('payer_email', 160)->nullable()->after('payer_last_name');
            $table->string('payer_phone', 30)->nullable()->after('payer_email');
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropColumn(['payer_first_name', 'payer_last_name', 'payer_email', 'payer_phone']);
        });
    }
};

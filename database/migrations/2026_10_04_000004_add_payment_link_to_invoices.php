<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lien de paiement par carte propre à une facture (montant fixe), sinon le lien général.
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('payment_link', 500)->nullable()->after('show_bank');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('payment_link');
        });
    }
};

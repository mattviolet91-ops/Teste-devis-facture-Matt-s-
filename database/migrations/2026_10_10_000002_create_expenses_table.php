<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Achats (matériaux, location, déchetterie…) pour calculer la marge de chaque chantier. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('spent_on')->index();
            $table->string('label', 160);
            $table->string('supplier', 120)->nullable();
            $table->string('category', 20)->default('materiaux');
            // Montants en centimes : payé TTC, dont TVA (0 en franchise de TVA).
            $table->bigInteger('amount_ttc');
            $table->bigInteger('vat')->default(0);
            // Chantier = devis accepté ; vide pour un frais général.
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_name')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};

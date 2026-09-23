<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            // Attribué à l'envoi ; un brouillon n'a pas de numéro.
            $table->string('number', 30)->nullable()->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('worksite_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200)->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedSmallInteger('validity_days')->default(30);
            $table->date('issue_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('refused_at')->nullable();
            $table->string('refusal_reason', 500)->nullable();
            $table->foreignId('replaces_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->foreignId('replaced_by_id')->nullable()->constrained('quotes')->nullOnDelete();
            // Remise globale : « percent » (centièmes de %) ou « amount » (centimes).
            $table->string('discount_type', 10)->nullable();
            $table->unsignedInteger('discount_value')->default(0);
            // Régime de TVA figé au moment de la rédaction.
            $table->string('vat_regime', 20)->default('assujetti');
            $table->bigInteger('total_ht')->default(0);
            $table->bigInteger('total_vat')->default(0);
            $table->bigInteger('total_ttc')->default(0);
            $table->string('work_start', 120)->nullable();
            $table->string('work_duration', 120)->nullable();
            $table->text('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('search_index')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Lignes des devis (et plus tard des factures) : section, prestation ou texte libre.
        Schema::create('document_lines', function (Blueprint $table) {
            $table->id();
            $table->morphs('document');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('type', 10)->default('item');
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            // Quantité en millièmes (1,5 m² = 1500) pour éviter les arrondis.
            $table->bigInteger('quantity')->default(1000);
            $table->string('unit', 20)->nullable();
            $table->bigInteger('unit_price')->default(0);
            $table->unsignedInteger('vat_rate')->default(0);
            $table->unsignedInteger('discount_percent')->default(0);
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_offered')->default(false);
            $table->boolean('hide_prices')->default(false);
            $table->bigInteger('total_ht')->default(0);
            $table->foreignId('catalog_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_lines');
        Schema::dropIfExists('quotes');
    }
};

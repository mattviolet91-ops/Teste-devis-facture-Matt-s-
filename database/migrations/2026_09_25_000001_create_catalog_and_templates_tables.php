<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // Prestation réutilisable : un titre, la liste des étapes (description),
        // une unité et un prix HT en centimes.
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('catalog_categories')->nullOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('unit', 20)->default('forfait');
            $table->integer('unit_price')->default(0);
            // Taux en centièmes de % ; null = taux par défaut des réglages.
            $table->unsignedInteger('vat_rate')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('search_index')->nullable();
            $table->timestamps();
        });

        // Textes prédéfinis : notes, conditions de paiement, étapes types.
        Schema::create('text_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('label', 120);
            $table->text('body');
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['type', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('text_templates');
        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('catalog_categories');
    }
};

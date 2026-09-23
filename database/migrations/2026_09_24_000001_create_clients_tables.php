<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('particulier');
            $table->string('status', 20)->default('prospect');
            $table->string('civility', 20)->nullable();
            $table->string('first_name', 80)->nullable();
            $table->string('last_name', 80)->nullable();
            $table->string('company_name', 160)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('phone_2', 30)->nullable();
            $table->string('address', 160)->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('source', 30)->nullable();
            $table->string('source_detail', 160)->nullable();
            $table->text('notes')->nullable();
            // Texte normalisé (minuscules, sans accents, téléphones en chiffres) pour la recherche.
            $table->text('search_index')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
            $table->index('company_name');
        });

        Schema::create('worksites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120)->nullable();
            $table->string('address', 160);
            $table->string('postal_code', 10);
            $table->string('city', 80);
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->text('access_notes')->nullable();
            $table->string('roof_type', 30)->nullable();
            $table->decimal('roof_surface', 8, 2)->nullable();
            $table->string('roof_pitch', 30)->nullable();
            $table->unsignedTinyInteger('levels')->nullable();
            $table->string('accessibility', 30)->nullable();
            $table->text('notes')->nullable();
            $table->text('search_index')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worksites');
        Schema::dropIfExists('clients');
    }
};

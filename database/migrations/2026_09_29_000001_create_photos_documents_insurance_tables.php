<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Photos de chantier (fichiers privés, compressés, avec miniature).
        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worksite_id')->constrained()->cascadeOnDelete();
            // avant, pendant, apres, probleme, reparation, autre
            $table->string('category', 20)->default('avant')->index();
            $table->string('caption', 255)->nullable();
            $table->string('path');
            $table->string('thumb_path');
            // Version annotée (flèches, entourages) : l'original est conservé.
            $table->string('annotated_path')->nullable();
            $table->unsignedSmallInteger('width')->default(0);
            $table->unsignedSmallInteger('height')->default(0);
            $table->unsignedInteger('size')->default(0);
            $table->timestamp('taken_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Photos jointes au PDF d'un devis ou d'une facture (annexe).
        Schema::create('document_photo', function (Blueprint $table) {
            $table->id();
            $table->morphs('document');
            $table->foreignId('photo_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unique(['document_type', 'document_id', 'photo_id']);
        });

        // Documents rangés sur une fiche client (plans, attestations, courriers…).
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Historique des attestations d'assurance décennale.
        Schema::create('insurance_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('insurer', 160);
            $table->string('policy_number', 60);
            $table->date('valid_from');
            $table->date('valid_until')->index();
            $table->string('path')->nullable();
            $table->string('original_name', 160)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_certificates');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('document_photo');
        Schema::dropIfExists('photos');
    }
};

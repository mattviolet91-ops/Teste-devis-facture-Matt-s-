<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Devis et factures émis avec Wix, conservés en archive (PDF d'origine).
        // Ils gardent leur numéro Wix et ne comptent ni dans la numérotation ni dans le CA.
        Schema::create('wix_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 10);
            $table->string('number', 30);
            $table->string('title', 200)->nullable();
            $table->date('issue_date')->nullable()->index();
            $table->bigInteger('total')->default(0);
            $table->string('status', 30)->nullable();
            $table->string('path');
            $table->string('original_name', 200)->nullable();
            $table->timestamps();
            $table->unique(['kind', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wix_archives');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Demandes de devis envoyées depuis le site internet.
        Schema::create('quote_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worksite_id')->nullable()->constrained()->nullOnDelete();
            $table->json('works')->nullable();
            $table->text('message')->nullable();
            $table->string('availability', 200)->nullable();
            // new, handled
            $table->string('status', 10)->default('new')->index();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_requests');
    }
};

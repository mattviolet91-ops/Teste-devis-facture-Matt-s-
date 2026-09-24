<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Emails du formulaire du site déjà lus dans la boîte Gmail (pour ne jamais les traiter deux fois).
        Schema::create('site_emails', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 255)->unique();
            $table->foreignId('quote_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 255)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_emails');
    }
};

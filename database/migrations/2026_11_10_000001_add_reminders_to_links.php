<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Relance automatique des documents à signer et des liens de paiement en attente. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['attachments', 'payment_requests'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('reminded_at')->nullable();
                $table->unsignedTinyInteger('reminder_count')->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['attachments', 'payment_requests'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['reminded_at', 'reminder_count']);
            });
        }
    }
};

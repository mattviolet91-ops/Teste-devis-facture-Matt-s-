<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Documents (scans, fichiers) rangés sur un devis ou une facture, en plus de la fiche client. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->foreignId('quote_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->after('quote_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropConstrainedForeignId('quote_id');
        });
    }
};

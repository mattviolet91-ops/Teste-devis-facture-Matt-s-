<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encaissement d'un montant libre : demande de paiement par carte envoyée au client.
 * La facture (acompte ou simple) n'est créée qu'une fois le paiement confirmé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            // deposit (facture d'acompte) ou standard (facture simple)
            $table->string('invoice_kind', 12);
            $table->string('label', 150);
            $table->bigInteger('amount');
            $table->integer('vat_rate')->default(0);
            $table->string('token', 64)->unique();
            // pending, paid, cancelled
            $table->string('status', 12)->default('pending')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('online_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_id')->nullable()->change();
            $table->foreignId('payment_request_id')->nullable()->after('invoice_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('online_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_request_id');
        });
        Schema::dropIfExists('payment_requests');
    }
};

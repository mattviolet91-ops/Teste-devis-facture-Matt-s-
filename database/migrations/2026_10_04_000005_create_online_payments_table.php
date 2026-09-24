<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paiements par carte via myPOS Checkout : une ligne par tentative du client.
        Schema::create('online_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_id', 40)->unique();
            $table->bigInteger('amount');
            // pending, paid, cancelled, error
            $table->string('status', 12)->default('pending');
            $table->string('transaction_ref', 80)->nullable()->unique();
            $table->boolean('test')->default(false);
            $table->string('error', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_payments');
    }
};

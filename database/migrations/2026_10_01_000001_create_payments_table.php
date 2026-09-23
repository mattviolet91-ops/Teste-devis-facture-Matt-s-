<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Règlements reçus sur les factures (un paiement partiel est possible).
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->date('paid_at')->index();
            $table->bigInteger('amount');
            // virement, cheque, especes, cb, mypos, autre
            $table->string('method', 20);
            $table->string('method_detail', 80)->nullable();
            $table->string('reference', 80)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->date('paid_at')->nullable()->after('cancelled_at');
            $table->unsignedSmallInteger('reminder_count')->default(0)->after('amount_paid');
            $table->timestamp('last_reminder_at')->nullable()->after('reminder_count');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['paid_at', 'reminder_count', 'last_reminder_at']));
        Schema::dropIfExists('payments');
    }
};

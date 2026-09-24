<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Délai conseillé avant de reproposer la prestation (ex. démoussage : 36 mois).
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('maintenance_months')->nullable()->after('vat_rate');
        });

        // Rappels d'entretien : « votre démoussage date de 3 ans ».
        Schema::create('maintenance_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('worksite_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label', 200);
            $table->date('done_on');
            $table->date('due_on')->index();
            $table->string('status', 12)->default('pending')->index();
            $table->unsignedSmallInteger('contact_count')->default(0);
            $table->timestamp('contacted_at')->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'catalog_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_reminders');
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropColumn('maintenance_months');
        });
    }
};

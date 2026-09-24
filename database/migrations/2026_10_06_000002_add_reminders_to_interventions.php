<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rappel 1 ou 2 jours avant (notification, et email au client si demandé).
        Schema::table('interventions', function (Blueprint $table) {
            $table->unsignedTinyInteger('remind_days')->nullable()->default(1)->after('notes');
            $table->boolean('remind_client')->default(false)->after('remind_days');
            $table->timestamp('reminder_sent_at')->nullable()->after('remind_client');
        });
    }

    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropColumn(['remind_days', 'remind_client', 'reminder_sent_at']);
        });
    }
};

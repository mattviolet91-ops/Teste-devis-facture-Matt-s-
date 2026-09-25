<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Relances automatiques des devis envoyés et pas encore signés.
        Schema::table('quotes', function (Blueprint $table) {
            $table->unsignedTinyInteger('follow_up_count')->default(0)->after('sent_at');
            $table->timestamp('last_follow_up_at')->nullable()->after('follow_up_count');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn(['follow_up_count', 'last_follow_up_at']);
        });
    }
};

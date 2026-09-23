<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dernier numéro réellement attribué : le prochain numéro ne peut jamais redescendre en dessous.
        Schema::table('number_sequences', function (Blueprint $table) {
            $table->unsignedInteger('last_issued_number')->nullable()->after('next_number');
        });
    }

    public function down(): void
    {
        Schema::table('number_sequences', function (Blueprint $table) {
            $table->dropColumn('last_issued_number');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intervention urgente déclarée après la signature : le client confirme lui-même,
 * par une signature, l'avoir expressément demandée (preuve gardée à part ; le devis
 * signé, lui, ne change pas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->timestamp('urgent_confirmed_at')->nullable()->after('urgent_repair');
            $table->string('urgent_reason', 500)->nullable()->after('urgent_confirmed_at');
            $table->string('urgent_signed_name', 160)->nullable()->after('urgent_reason');
            $table->string('urgent_signature_path')->nullable()->after('urgent_signed_name');
            $table->string('urgent_confirmed_ip', 45)->nullable()->after('urgent_signature_path');
            $table->boolean('urgent_confirmed_on_site')->default(false)->after('urgent_confirmed_ip');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', fn (Blueprint $table) => $table->dropColumn([
            'urgent_confirmed_at', 'urgent_reason', 'urgent_signed_name', 'urgent_signature_path', 'urgent_confirmed_ip', 'urgent_confirmed_on_site',
        ]));
    }
};

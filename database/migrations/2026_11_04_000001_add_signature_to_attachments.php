<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Documents à faire signer par le client, sur place ou par un lien. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('size');
            $table->timestamp('signature_requested_at')->nullable()->after('public_token');
            $table->timestamp('viewed_at')->nullable()->after('signature_requested_at');
            $table->timestamp('signed_at')->nullable()->after('viewed_at');
            $table->string('signed_name', 160)->nullable()->after('signed_at');
            $table->string('signed_ip', 45)->nullable()->after('signed_name');
            $table->string('signed_user_agent')->nullable()->after('signed_ip');
            $table->boolean('signed_on_site')->default(false)->after('signed_user_agent');
            $table->string('signature_path')->nullable()->after('signed_on_site');
            $table->string('signed_path')->nullable()->after('signature_path');
            $table->string('original_hash', 64)->nullable()->after('signed_path');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['public_token', 'signature_requested_at', 'viewed_at', 'signed_at', 'signed_name', 'signed_ip',
                'signed_user_agent', 'signed_on_site', 'signature_path', 'signed_path', 'original_hash']);
        });
    }
};

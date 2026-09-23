<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Photos ajoutées directement depuis un devis ou une facture : rattachées au
 * client, et au chantier quand le document en a un.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::table('photos')->orderBy('id')->each(function ($photo) {
            $clientId = DB::table('worksites')->where('id', $photo->worksite_id)->value('client_id');
            DB::table('photos')->where('id', $photo->id)->update(['client_id' => $clientId]);
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->foreignId('worksite_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};

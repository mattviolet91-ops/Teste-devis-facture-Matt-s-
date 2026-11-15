<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agenda de chacun : chaque rendez-vous, chantier ou rappel est « pour » une personne.
 * Le commercial ne voit que le sien ; le gérant voit tout. Les existants vont à leur auteur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        $admin = DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');
        DB::table('interventions')->whereNotNull('created_by')->update(['user_id' => DB::raw('created_by')]);
        if ($admin) {
            DB::table('interventions')->whereNull('user_id')->update(['user_id' => $admin]);
        }
    }

    public function down(): void
    {
        Schema::table('interventions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};

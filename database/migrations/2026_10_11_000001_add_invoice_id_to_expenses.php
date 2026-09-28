<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Les frais sont désormais saisis sur la facture (section « Frais », visible par le gérant seul). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('quote_id')->constrained()->cascadeOnDelete();
        });

        // Achats déjà saisis sur un chantier : rattachés à la première facture de ce devis.
        foreach (DB::table('expenses')->whereNull('invoice_id')->whereNotNull('quote_id')->get() as $expense) {
            $invoiceId = DB::table('invoices')->where('quote_id', $expense->quote_id)->where('kind', '!=', 'credit')->orderBy('id')->value('id');
            if ($invoiceId) {
                DB::table('expenses')->where('id', $expense->id)->update(['invoice_id' => $invoiceId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};

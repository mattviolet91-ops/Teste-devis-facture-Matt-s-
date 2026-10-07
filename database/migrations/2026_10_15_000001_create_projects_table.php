<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chantiers : un chantier peut regrouper plusieurs devis et leurs factures
 * (ou des factures faites sans devis). Les frais sont rangés par chantier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('worksite_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->timestamps();
        });
        foreach (['quotes', 'invoices', 'expenses'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            });
        }

        // Chantiers existants : un par devis accepté ou facturé, un par facture sans devis.
        $issued = ['sent', 'partial', 'paid'];
        $create = function (object $doc, string $fallback) {
            return DB::table('projects')->insertGetId([
                'client_id' => $doc->client_id, 'worksite_id' => $doc->worksite_id,
                'title' => mb_substr(trim((string) $doc->title) ?: $fallback, 0, 160),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        };
        $billedQuotes = DB::table('invoices')->whereIn('status', $issued)->whereNotNull('quote_id')->pluck('quote_id')->unique()->all();
        foreach (DB::table('quotes')->whereNull('deleted_at')->where(fn ($q) => $q->where('status', 'accepted')->orWhereIn('id', $billedQuotes))->get() as $quote) {
            $project = $create($quote, 'Devis '.$quote->number);
            DB::table('quotes')->where('id', $quote->id)->update(['project_id' => $project]);
            DB::table('invoices')->where('quote_id', $quote->id)->update(['project_id' => $project]);
            DB::table('expenses')->where('quote_id', $quote->id)->update(['project_id' => $project]);
        }
        foreach (DB::table('invoices')->whereNull('quote_id')->whereNull('deleted_at')->whereIn('status', $issued)->where('kind', '!=', 'credit')->get() as $invoice) {
            $project = $create($invoice, 'Facture '.$invoice->number);
            DB::table('invoices')->where('id', $invoice->id)->update(['project_id' => $project]);
            DB::table('expenses')->whereNull('quote_id')->where('invoice_id', $invoice->id)->update(['project_id' => $project]);
        }
    }

    public function down(): void
    {
        foreach (['quotes', 'invoices', 'expenses'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('project_id'));
        }
        Schema::dropIfExists('projects');
    }
};

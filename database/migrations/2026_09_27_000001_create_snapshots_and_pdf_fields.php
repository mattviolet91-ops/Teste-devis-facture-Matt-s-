<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PDF figé de chaque document envoyé, avec son empreinte : ce que le
        // client a reçu ne change plus, même si les réglages évoluent.
        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();
            $table->morphs('document');
            $table->string('path');
            $table->char('sha256', 64);
            $table->unsignedInteger('size');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('quotes', function (Blueprint $table) {
            // Estimation des déchets du chantier (mention des devis de travaux).
            $table->string('waste_estimate', 200)->nullable()->after('work_duration');
            $table->boolean('show_bank')->default(false)->after('waste_estimate');
        });

        Schema::table('invoices', function (Blueprint $table) {
            // Date ou période d'exécution des travaux (mention obligatoire sur facture).
            $table->string('work_period', 160)->nullable()->after('title');
            $table->boolean('show_bank')->default(false)->after('work_period');
        });

        Schema::table('clients', function (Blueprint $table) {
            // SIRET des clients professionnels (imprimé sur leurs factures).
            $table->string('siret', 14)->nullable()->after('company_name');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('siret'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['work_period', 'show_bank']));
        Schema::table('quotes', fn (Blueprint $table) => $table->dropColumn(['waste_estimate', 'show_bank']));
        Schema::dropIfExists('snapshots');
    }
};

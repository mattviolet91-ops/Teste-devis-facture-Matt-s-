<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Factures et avoirs dans une même table : un avoir est une facture de
        // type « credit » numérotée dans sa propre suite (AV-…).
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            // Attribué à l'envoi ; un brouillon n'a pas de numéro.
            $table->string('number', 30)->nullable()->unique();
            // standard, deposit (acompte), progress (situation), final (solde), credit (avoir).
            $table->string('kind', 12)->default('standard')->index();
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('worksite_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            // Avoir → facture annulée ; facture corrigée → facture d'origine.
            $table->foreignId('cancels_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('corrects_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('title', 200)->nullable();
            // Part du devis facturée (acompte / situation), en centièmes de %.
            $table->unsignedInteger('percent')->nullable();
            $table->date('issue_date')->nullable();
            $table->unsignedSmallInteger('due_days')->default(0);
            $table->date('due_date')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('discount_type', 10)->nullable();
            $table->unsignedInteger('discount_value')->default(0);
            $table->string('vat_regime', 20)->default('assujetti');
            $table->bigInteger('total_ht')->default(0);
            $table->bigInteger('total_vat')->default(0);
            $table->bigInteger('total_ttc')->default(0);
            // Alimenté par les paiements (phase 10).
            $table->bigInteger('amount_paid')->default(0);
            $table->text('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('search_index')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        $now = now();
        $position = (int) DB::table('text_templates')->where('type', 'note')->max('position');
        DB::table('text_templates')->insert([
            [
                'type' => 'note', 'label' => 'Mentions clients professionnels', 'is_default' => false, 'position' => $position + 1,
                'body' => "En cas de retard de paiement, des pénalités sont exigibles au taux d'intérêt appliqué par la BCE "
                    ."à son opération de refinancement la plus récente majoré de 10 points, ainsi qu'une indemnité forfaitaire "
                    ."pour frais de recouvrement de 40 € (art. L441-10 du Code de commerce). Pas d'escompte pour paiement anticipé.",
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('text_templates')->where('label', 'Mentions clients professionnels')->delete();
        Schema::dropIfExists('invoices');
    }
};

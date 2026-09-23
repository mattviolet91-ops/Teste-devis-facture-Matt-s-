<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modèles d'emails avec variables ({salutation}, {numero}, {montant}…).
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            // quote (devis), invoice (factures et avoirs) ou any (message libre).
            $table->string('context', 10)->default('any')->index();
            $table->string('subject', 200);
            $table->text('body');
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // Historique des emails envoyés depuis l'application.
        Schema::create('sent_emails', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('document');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to', 255);
            $table->string('cc', 255)->nullable();
            $table->string('subject', 200);
            $table->text('body');
            $table->string('attachment', 160)->nullable();
            $table->string('status', 10)->default('sent');
            $table->string('error', 500)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        $signature = "Cordialement,\n{entreprise}\n{telephone}";
        $templates = [
            ['Envoi du devis', 'quote', 'Votre devis {numero} — {entreprise}', true,
                "{salutation}\n\nVeuillez trouver ci-joint notre devis n° {numero} pour {objet}, d'un montant de {montant}.\n\n"
                ."Il est valable jusqu'au {date_validite}. Pour l'accepter, il vous suffit de nous le retourner daté et signé, avec la mention « Bon pour accord ».\n\n"
                ."Nous restons à votre disposition pour toute question.\n\n$signature"],
            ['Relance du devis', 'quote', 'Votre devis {numero} — {entreprise}', false,
                "{salutation}\n\nNous vous avons adressé le devis n° {numero} pour {objet}. Avez-vous pu en prendre connaissance ?\n\n"
                ."Nous restons disponibles pour en parler ou l'adapter à vos besoins. Il est valable jusqu'au {date_validite}.\n\n$signature"],
            ['Envoi de la facture', 'invoice', '{document_titre} {numero} — {entreprise}', true,
                "{salutation}\n\nVeuillez trouver ci-joint {document} n° {numero} d'un montant de {montant}, {echeance}.\n\n"
                ."Nous vous remercions de votre confiance.\n\n$signature"],
            ['Relance de paiement', 'invoice', 'Rappel : facture {numero} — {entreprise}', false,
                "{salutation}\n\nSauf erreur de notre part, la facture n° {numero} d'un montant de {reste_a_payer} n'a pas encore été réglée (échéance : {date_echeance}).\n\n"
                ."Vous la trouverez de nouveau ci-jointe. Si le règlement a été effectué entre-temps, merci de ne pas tenir compte de ce message.\n\n$signature"],
            ['Remerciement (paiement reçu)', 'invoice', 'Merci pour votre règlement — {entreprise}', false,
                "{salutation}\n\nNous avons bien reçu votre règlement pour la facture n° {numero}. Merci pour votre confiance.\n\n"
                ."N'hésitez pas à nous recommander autour de vous !\n\n$signature"],
            ['Message libre', 'any', '{entreprise}', true, "{salutation}\n\n\n\n$signature"],
        ];

        $now = now();
        foreach ($templates as $position => [$name, $context, $subject, $default, $body]) {
            DB::table('email_templates')->insert([
                'name' => $name, 'context' => $context, 'subject' => $subject, 'body' => $body,
                'is_default' => $default, 'position' => $position + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_emails');
        Schema::dropIfExists('email_templates');
    }
};

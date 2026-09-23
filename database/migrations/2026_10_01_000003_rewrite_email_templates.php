<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nouveaux objets et textes des modèles d'emails (demande du 23/09/2026) :
 * ton plus chaleureux, signature en ligne mise en avant, jamais de nom personnel.
 */
return new class extends Migration
{
    public function up(): void
    {
        $signature = "Bien cordialement,\n{entreprise}\n{telephone}";

        $templates = [
            'Envoi du devis' => [
                'Votre devis n° {numero} — {entreprise}',
                "{salutation}\n\nMerci pour votre confiance. Comme convenu, vous trouverez ci-joint notre devis n° {numero} pour {objet}, d'un montant de {montant}.\n\n"
                ."Vous pouvez le consulter et l'accepter en ligne en quelques secondes, avec une signature directement sur votre téléphone :\n{lien}\n\n"
                ."Ce devis est valable jusqu'au {date_validite}. Pour toute question ou modification, répondez simplement à cet email ou appelez-nous au {telephone}.\n\n$signature",
            ],
            'Relance du devis' => [
                'Suite à notre devis n° {numero} — {entreprise}',
                "{salutation}\n\nNous revenons vers vous au sujet de notre devis n° {numero} pour {objet} ({montant}).\n\n"
                ."Avez-vous pu en prendre connaissance ? Nous restons à votre disposition pour en discuter ou l'adapter à vos besoins. Il est valable jusqu'au {date_validite}.\n\n"
                ."Pour l'accepter en ligne, il suffit de cliquer sur le bouton ci-dessous :\n{lien}\n\n$signature",
            ],
            'Envoi de la facture' => [
                '{document_titre} n° {numero} — {entreprise}',
                "{salutation}\n\nVeuillez trouver ci-joint {document} n° {numero} d'un montant de {montant}, {echeance}.\n\n"
                ."Vous pouvez aussi la consulter et la télécharger en ligne :\n{lien}\n\n"
                ."Nous vous remercions pour votre confiance et restons à votre disposition.\n\n$signature",
            ],
            'Relance de paiement' => [
                'Rappel : facture n° {numero} en attente de règlement',
                "{salutation}\n\nSauf erreur de notre part, le règlement de la facture n° {numero} d'un montant de {reste_a_payer} ne nous est pas encore parvenu (échéance : {date_echeance}).\n\n"
                ."Vous la retrouverez ci-jointe, ainsi qu'en ligne :\n{lien}\n\n"
                ."Si le règlement a été effectué entre-temps, merci de ne pas tenir compte de ce message.\n\n$signature",
            ],
            'Remerciement (paiement reçu)' => [
                'Merci pour votre règlement — {entreprise}',
                "{salutation}\n\nNous avons bien reçu votre règlement de la facture n° {numero}. Merci pour votre confiance !\n\n"
                ."Votre satisfaction est notre meilleure publicité : n'hésitez pas à nous recommander autour de vous.\n\n$signature",
            ],
            'Message libre' => [
                'Message de {entreprise}',
                "{salutation}\n\n\n\n$signature",
            ],
        ];

        foreach ($templates as $name => [$subject, $body]) {
            DB::table('email_templates')->where('name', $name)->update(['subject' => $subject, 'body' => $body, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Rien à restaurer.
    }
};

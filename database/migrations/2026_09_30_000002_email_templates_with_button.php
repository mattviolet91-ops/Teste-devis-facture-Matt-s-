<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le lien client s'affiche désormais comme un bouton dans les emails : on
 * adapte la phrase d'introduction des modèles par défaut (s'ils n'ont pas été modifiés).
 */
return new class extends Migration
{
    public function up(): void
    {
        $sentences = [
            "Vous pouvez aussi consulter et accepter ce devis en ligne, avec signature sur votre téléphone :\n{lien}" => "Pour consulter et accepter votre devis en ligne (signature directement sur votre téléphone), cliquez sur le bouton ci-dessous :\n{lien}",
            "Pour l'accepter en ligne :\n{lien}" => "Pour l'accepter en ligne, cliquez sur le bouton ci-dessous :\n{lien}",
            "Consulter et télécharger la facture en ligne :\n{lien}" => "Vous pouvez aussi la consulter et la télécharger en ligne :\n{lien}",
        ];

        foreach (DB::table('email_templates')->get() as $template) {
            $body = strtr($template->body, $sentences);
            if ($body !== $template->body) {
                DB::table('email_templates')->where('id', $template->id)->update(['body' => $body]);
            }
        }
    }

    public function down(): void
    {
        // Rien à restaurer.
    }
};

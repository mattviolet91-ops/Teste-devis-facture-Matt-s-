<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lien client : jeton secret (non devinable, sans expiration) et signature en ligne.
        Schema::table('quotes', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('number');
            $table->timestamp('viewed_at')->nullable()->after('sent_at');
            $table->string('signed_name', 160)->nullable()->after('refusal_reason');
            $table->string('signature_path')->nullable()->after('signed_name');
            $table->timestamp('signed_at')->nullable()->after('signature_path');
            $table->string('signed_ip', 45)->nullable()->after('signed_at');
            $table->string('signed_user_agent', 255)->nullable()->after('signed_ip');
            // Demande de modification envoyée par le client depuis le lien.
            $table->text('client_comment')->nullable()->after('signed_user_agent');
            $table->timestamp('change_requested_at')->nullable()->after('client_comment');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('number');
            $table->timestamp('viewed_at')->nullable()->after('sent_at');
        });

        // Modèles d'emails par défaut : ajout du lien client ({lien}).
        $links = [
            'Envoi du devis' => "Vous pouvez aussi consulter et accepter ce devis en ligne, avec signature sur votre téléphone :\n{lien}",
            'Relance du devis' => "Pour l'accepter en ligne :\n{lien}",
            'Envoi de la facture' => "Consulter et télécharger la facture en ligne :\n{lien}",
            'Relance de paiement' => "Consulter la facture en ligne :\n{lien}",
        ];
        foreach ($links as $name => $text) {
            $template = DB::table('email_templates')->where('name', $name)->first();
            if ($template && ! str_contains($template->body, '{lien}')) {
                $body = preg_replace('/\n\nCordialement,/', "\n\n$text\n\nCordialement,", $template->body, 1);
                DB::table('email_templates')->where('id', $template->id)->update(['body' => $body]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['public_token', 'viewed_at']));
        Schema::table('quotes', fn (Blueprint $table) => $table->dropColumn([
            'public_token', 'viewed_at', 'signed_name', 'signature_path', 'signed_at', 'signed_ip',
            'signed_user_agent', 'client_comment', 'change_requested_at',
        ]));
    }
};

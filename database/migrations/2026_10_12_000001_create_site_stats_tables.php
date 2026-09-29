<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statistiques du site internet :
 * - site_events : visites et clics mesurés par le petit script posé sur le site (sans cookie) ;
 * - site_daily_stats : visites par jour lues dans WordPress.com (Jetpack Stats).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_events', function (Blueprint $table) {
            $table->id();
            // pv (page vue), tel, mail, whatsapp, cta (bouton devis/contact), form (formulaire envoyé), out (lien sortant)
            $table->string('type', 12)->index();
            $table->string('path', 190)->nullable();
            $table->string('label', 80)->nullable();
            $table->string('referrer_host', 120)->nullable();
            $table->string('device', 10)->nullable();
            // Empreinte anonyme du visiteur, changée chaque jour (aucune adresse IP enregistrée).
            $table->char('visitor', 16)->index();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('site_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('day')->unique();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('visitors')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_events');
        Schema::dropIfExists('site_daily_stats');
    }
};

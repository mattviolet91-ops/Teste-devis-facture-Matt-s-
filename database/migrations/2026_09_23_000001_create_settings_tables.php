<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Réglages clé → valeur. Seules les valeurs modifiées sont stockées,
        // les valeurs par défaut vivent dans config/entreprise.php.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // Taux en centièmes de pour cent : 10 % = 1000.
        Schema::create('vat_rates', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->unsignedInteger('rate');
            $table->text('mention')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('label');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->unique();
            $table->string('prefix', 20);
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(4);
            $table->timestamps();
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 50);
            $table->nullableMorphs('subject');
            $table->string('description');
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        $now = now();
        $attestation = "Le client atteste que les travaux portent sur un local à usage d'habitation achevé depuis plus de deux ans "
            ."et qu'ils ne concourent pas à la production d'un immeuble neuf (art. 279-0 bis du CGI).";
        $attestationEnergie = "Le client atteste que les travaux d'amélioration de la qualité énergétique portent sur un local "
            ."à usage d'habitation achevé depuis plus de deux ans (art. 278-0 bis A du CGI).";

        DB::table('vat_rates')->insert([
            ['label' => 'TVA 10 %', 'rate' => 1000, 'mention' => $attestation, 'is_default' => true, 'is_active' => true, 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'TVA 20 %', 'rate' => 2000, 'mention' => null, 'is_default' => false, 'is_active' => true, 'position' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'TVA 5,5 %', 'rate' => 550, 'mention' => $attestationEnergie, 'is_default' => false, 'is_active' => true, 'position' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'Sans TVA', 'rate' => 0, 'mention' => null, 'is_default' => false, 'is_active' => true, 'position' => 4, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('units')->insert(collect([
            ['m²', 'Mètre carré'],
            ['ml', 'Mètre linéaire'],
            ['u', 'Unité'],
            ['forfait', 'Forfait'],
            ['h', 'Heure'],
        ])->map(fn ($unit, $i) => [
            'code' => $unit[0], 'label' => $unit[1], 'is_active' => true, 'position' => $i + 1,
            'created_at' => $now, 'updated_at' => $now,
        ])->all());

        DB::table('number_sequences')->insert([
            ['type' => 'quote', 'prefix' => 'DEV', 'next_number' => 1, 'padding' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'invoice', 'prefix' => 'FAC', 'next_number' => 1, 'padding' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['type' => 'credit_note', 'prefix' => 'AV', 'next_number' => 1, 'padding' => 4, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('units');
        Schema::dropIfExists('vat_rates');
        Schema::dropIfExists('settings');
    }
};

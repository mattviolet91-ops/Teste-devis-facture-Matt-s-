<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Données fictives pour les tests et les démonstrations.
 * Ne jamais lancer en production : php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Données de démonstration refusées en production.');

            return;
        }

        User::query()->firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Matt Violet', 'password' => 'demo-motdepasse-2026', 'role' => 'admin']
        );

        $dupont = Client::query()->create([
            'type' => 'particulier', 'status' => 'client', 'civility' => 'Mme', 'first_name' => 'Hélène', 'last_name' => 'Dupont',
            'phone' => '06 12 34 56 78', 'email' => 'helene.dupont@example.com',
            'address' => '12 rue des Tilleuls', 'postal_code' => '91300', 'city' => 'Massy',
            'source' => 'google', 'notes' => 'Préfère être appelée après 18 h.',
        ]);
        $dupont->worksites()->create([
            'address' => '12 rue des Tilleuls', 'postal_code' => '91300', 'city' => 'Massy',
            'access_notes' => 'Portail vert, se garer dans l\'allée. Chien gentil.',
            'roof_type' => 'tuile_mecanique', 'roof_surface' => 110, 'roof_pitch' => '35°', 'levels' => 2, 'accessibility' => 'echelle',
        ]);

        $syndic = Client::query()->create([
            'type' => 'syndic', 'status' => 'client', 'company_name' => 'Cabinet Martin Gestion', 'civility' => 'M.',
            'first_name' => 'Julien', 'last_name' => 'Martin', 'phone' => '01 69 00 11 22', 'email' => 'gestion@cabinet-martin.example',
            'address' => '4 avenue de Paris', 'postal_code' => '91120', 'city' => 'Palaiseau', 'source' => 'recommandation',
        ]);
        $syndic->worksites()->createMany([
            ['label' => 'Résidence Les Érables — bât. A', 'address' => '18 allée des Érables', 'postal_code' => '91300', 'city' => 'Massy',
                'contact_name' => 'M. Leroy (gardien)', 'contact_phone' => '06 98 76 54 32', 'access_notes' => 'Clés chez le gardien, loge au RDC.',
                'roof_type' => 'ardoise', 'roof_surface' => 420, 'levels' => 5, 'accessibility' => 'nacelle'],
            ['label' => 'Résidence Les Érables — bât. B', 'address' => '20 allée des Érables', 'postal_code' => '91300', 'city' => 'Massy',
                'roof_type' => 'ardoise', 'roof_surface' => 380, 'levels' => 5, 'accessibility' => 'nacelle'],
        ]);

        Client::query()->create([
            'type' => 'particulier', 'status' => 'prospect', 'civility' => 'M. et Mme', 'first_name' => 'Karim', 'last_name' => 'Benali',
            'phone' => '07 55 44 33 22', 'address' => '3 impasse du Moulin', 'postal_code' => '91140', 'city' => 'Villebon-sur-Yvette',
            'source' => 'site',
        ])->worksites()->create([
            'address' => '3 impasse du Moulin', 'postal_code' => '91140', 'city' => 'Villebon-sur-Yvette',
            'roof_type' => 'tuile_plate', 'roof_surface' => 85.5, 'levels' => 1,
        ]);

        Client::query()->create([
            'type' => 'agence', 'status' => 'prospect', 'company_name' => 'Agence Immo Essonne', 'first_name' => 'Sophie', 'last_name' => 'Garnier',
            'phone' => '01 60 10 20 30', 'email' => 'contact@immo-essonne.example', 'city' => 'Orsay', 'source' => 'bouche_a_oreille',
        ]);

        Client::factory()->count(20)->create()->each(fn (Client $client) => $client->worksites()->create(
            $client->only('address', 'postal_code', 'city')
        ));
    }
}

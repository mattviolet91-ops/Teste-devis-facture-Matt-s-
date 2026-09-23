<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Worksite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Worksite>
 */
class WorksiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'address' => fake()->streetAddress(),
            'postal_code' => '91300',
            'city' => 'Massy',
            'roof_type' => fake()->randomElement(array_keys(Worksite::ROOF_TYPES)),
            'roof_surface' => fake()->numberBetween(40, 220),
            'levels' => fake()->numberBetween(1, 3),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => 'particulier',
            'status' => 'prospect',
            'civility' => fake()->randomElement(Client::CIVILITIES),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '06'.fake()->numerify('########'),
            'address' => fake()->streetAddress(),
            'postal_code' => fake()->randomElement(['91300', '91120', '91140', '91400', '91940']),
            'city' => fake()->randomElement(['Massy', 'Palaiseau', 'Villebon-sur-Yvette', 'Orsay', 'Les Ulis']),
            'source' => fake()->randomElement(array_keys(Client::SOURCES)),
        ];
    }

    public function company(string $type = 'entreprise'): static
    {
        return $this->state(fn () => [
            'type' => $type,
            'company_name' => fake()->company(),
        ]);
    }

    public function customer(): static
    {
        return $this->state(fn () => ['status' => 'client']);
    }
}

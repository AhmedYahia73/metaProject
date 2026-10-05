<?php

namespace Database\Factories;

use App\Models\Paymob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Paymob>
 */
class PaymobFactory extends Factory
{
    protected $model = Paymob::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'Paymob Payment Gateway',
            'logo' => 'paymob/fake_logo.png',
            'type' => 'test',
            'callback' => 'https://example.com/api/paymob/callback',
            'api_key' => fake()->sha256(),
            'iframe_id' => (string) fake()->numberBetween(100000, 999999),
            'integration_id' => (string) fake()->numberBetween(100000, 999999),
            'Hmac' => fake()->sha256(),
        ];
    }
}

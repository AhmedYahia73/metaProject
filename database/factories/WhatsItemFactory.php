<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WhatsItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsItem>
 */
class WhatsItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'phone' => fake()->unique()->numerify('2010########'),
            'phone_number_id' => (string) fake()->unique()->numberBetween(100000000000000, 999999999999999),
            'waba_id' => (string) fake()->numberBetween(100000000000000, 999999999999999),
            'access_token' => 'fake_meta_access_token',
            'phone_status' => 'active',
            'phone_verified_at' => now(),
            'android_link' => fake()->optional()->url(),
            'ios_link' => fake()->optional()->url(),
            'msg_number' => fake()->numberBetween(0, 5000),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone_status' => 'pending_otp',
            'phone_verified_at' => null,
            'msg_number' => 0,
        ]);
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone_status' => 'verified',
            'phone_verified_at' => now(),
        ]);
    }
}

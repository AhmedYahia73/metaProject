<?php

namespace Database\Factories;

use App\Models\MessengerAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MessengerAccount>
 */
class MessengerAccountFactory extends Factory
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
            'page_id' => (string) fake()->unique()->numerify('##############'),
            'page_name' => fake()->company().' Page',
            'page_access_token' => 'EAA'.Str::random(60),
            'verify_token' => (string) Str::uuid(),
            'status' => 'active',
            'ai_context' => null,
            'ai_file' => null,
            'android_link' => null,
            'ios_link' => null,
            'msg_number' => 0,
        ];
    }

    /**
     * Mark the account as disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'disabled',
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\InstagramItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InstagramItem>
 */
class InstagramItemFactory extends Factory
{
    protected $model = InstagramItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'instagram_id' => (string) fake()->unique()->numerify('1784140#########'),
            'username' => fake()->unique()->userName(),
            'name' => fake()->company(),
            'profile_picture_url' => 'https://via.placeholder.com/150',
            'page_id' => (string) fake()->unique()->numerify('##############'),
            'access_token' => 'EAA'.Str::random(60),
            'verify_token' => (string) Str::uuid(),
            'status' => 'active',
            'ai_context' => null,
            'ai_file' => null,
            'android_link' => null,
            'ios_link' => null,
            'website_url' => null,
            'msg_number' => 0,
        ];
    }

    /**
     * Mark the item as disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'disabled',
        ]);
    }

    /**
     * Mark the item as active with messages quota.
     */
    public function withQuota(int $msgs = 100): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'msg_number' => $msgs,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\DailyToken;
use App\Models\WhatsItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyToken>
 */
class DailyTokenFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<DailyToken>
     */
    protected $model = DailyToken::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => now()->toDateString(),
            'tokens' => fake()->numberBetween(100, 5000),
            'whats_item_id' => WhatsItem::factory(),
            'messenger_account_id' => null,
            'instagram_item_id' => null,
        ];
    }
}

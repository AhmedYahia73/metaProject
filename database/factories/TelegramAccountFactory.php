<?php

namespace Database\Factories;

use App\Models\TelegramAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TelegramAccount>
 */
class TelegramAccountFactory extends Factory
{
    protected $model = TelegramAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bot_token' => '123456789:'.Str::random(35),
            'bot_id' => (string) fake()->unique()->numerify('##########'),
            'bot_username' => fake()->unique()->userName().'_bot',
            'bot_name' => fake()->name().' Bot',
            'verify_token' => (string) Str::uuid(),
            'status' => 'active',
            'ai_context' => 'أنت موظف خدمة عملاء ودود',
            'ai_file' => null,
            'android_link' => 'https://play.google.com/store/apps/details?id=com.smartego.app',
            'ios_link' => 'https://apps.apple.com/app/smartego/id123456789',
            'website_url' => 'https://smartego.org',
            'msg_number' => 100,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'status' => 'disabled',
        ]);
    }
}

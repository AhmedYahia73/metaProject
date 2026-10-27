<?php

namespace Database\Factories;

use App\Models\TikTokAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TikTokAccount>
 */
class TikTokAccountFactory extends Factory
{
    protected $model = TikTokAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'open_id' => 'act_'.fake()->unique()->numerify('##########'),
            'username' => fake()->unique()->userName(),
            'display_name' => fake()->name(),
            'avatar_url' => 'https://p16-sign-va.tiktokcdn.com/avatar.jpeg',
            'access_token' => 'act.'.Str::random(64),
            'refresh_token' => 'rft.'.Str::random(64),
            'token_expires_at' => now()->addDay(),
            'refresh_token_expires_at' => now()->addYear(),
            'verify_token' => (string) Str::uuid(),
            'status' => 'active',
            'ai_context' => 'أنت موظف خدمة عملاء ودود في تيك توك',
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

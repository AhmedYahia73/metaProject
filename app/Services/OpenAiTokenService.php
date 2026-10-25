<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiTokenService
{
    /**
     * Cache duration in seconds (1 minute matches the OpenAI rate limit window).
     */
    public const CACHE_TTL_SECONDS = 60;

    /**
     * Get the remaining tokens for the integrated OpenAI service.
     */
    public function getRemainingTokens(): int
    {
        // 1. Check if manually configured via settings table or env
        $configuredOverride = config('services.openai.remaining_tokens', env('OPENAI_REMAINING_TOKENS'));
        if ($configuredOverride !== null && is_numeric($configuredOverride)) {
            return (int) $configuredOverride;
        }

        $settingOverride = Setting::where('name', 'ai_remaining_tokens')->value('value');
        if ($settingOverride !== null && is_numeric($settingOverride)) {
            return (int) $settingOverride;
        }

        // 2. Return cached value if available
        return (int) Cache::remember('openai_remaining_tokens', self::CACHE_TTL_SECONDS, function (): int {
            return $this->fetchFromOpenAi();
        });
    }

    /**
     * Make a lightweight call to OpenAI API to inspect rate limit headers and quota.
     */
    public function fetchFromOpenAi(): int
    {
        $apiKey = config('openai.api_key') ?: env('OPENAI_API_KEY');

        if (empty($apiKey)) {
            return 0;
        }

        $rawBaseUrl = config('openai.base_uri') ?: env('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1';
        $baseUrl = rtrim((string) $rawBaseUrl, '/');
        $model = config('openai.model') ?: env('OPENAI_MODEL') ?: 'gpt-4o-mini';

        try {
            $response = Http::timeout(5)
                ->withToken($apiKey)
                ->post("{$baseUrl}/chat/completions", [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => 'hi'],
                    ],
                    'max_tokens' => 1,
                ]);

            // Quota / credit balance exhausted
            if ($response->status() === 429) {
                $error = $response->json('error');
                $errorCode = is_array($error) ? ($error['code'] ?? null) : null;
                $errorType = is_array($error) ? ($error['type'] ?? null) : null;

                if ($errorCode === 'credit_balance_exhausted' || $errorType === 'insufficient_quota') {
                    return 0;
                }
            }

            // Check rate-limit remaining tokens headers
            $remainingHeader = $response->header('x-ratelimit-remaining-tokens')
                ?? $response->header('x-ratelimit-remaining-project-tokens');

            if ($remainingHeader !== null && is_numeric($remainingHeader)) {
                return (int) $remainingHeader;
            }

            return 0;
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch OpenAI remaining tokens: '.$e->getMessage());

            return 0;
        }
    }
}

<?php

namespace App\Services;

use App\Models\DailyToken;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiTokenService
{
    /**
     * Cache duration in seconds for live OpenAI API checks.
     */
    public const CACHE_TTL_SECONDS = 60;

    /**
     * Get the remaining tokens for the integrated OpenAI service.
     * Decrements dynamically based on actual token consumption in DailyToken.
     */
    public function getRemainingTokens(?int $userId = null): int
    {
        $stats = $this->getTokenStats($userId);

        return $stats['remaining_tokens'];
    }

    /**
     * Get full token statistics: total allocated, used, and remaining.
     *
     * @return array{total_tokens: int, used_tokens: int, remaining_tokens: int}
     */
    public function getTokenStats(?int $userId = null, ?string $from = null, ?string $to = null): array
    {
        $totalTokens = $this->getTotalAllocatedTokens();
        $usedTokens = $this->getConsumedTokens($userId, $from, $to);

        // If OpenAI quota is 0 (exhausted from API) or explicitly 0
        if ($totalTokens === 0) {
            return [
                'total_tokens' => 0,
                'used_tokens' => $usedTokens,
                'remaining_tokens' => 0,
            ];
        }

        // Calculate remaining tokens
        $remainingTokens = max(0, $totalTokens - $usedTokens);

        return [
            'total_tokens' => $totalTokens,
            'used_tokens' => $usedTokens,
            'remaining_tokens' => $remainingTokens,
        ];
    }

    /**
     * Check if a manual setting or environment variable is explicitly defining the token pool.
     */
    public function hasExplicitTokenSetting(): bool
    {
        $hasTotal = Setting::where('name', 'ai_total_tokens')->exists();
        $hasRemaining = Setting::where('name', 'ai_remaining_tokens')->exists();
        $hasEnvTotal = config('services.openai.total_tokens', env('OPENAI_TOTAL_TOKENS')) !== null;
        $hasEnvRemaining = config('services.openai.remaining_tokens', env('OPENAI_REMAINING_TOKENS')) !== null;

        return $hasTotal || $hasRemaining || $hasEnvTotal || $hasEnvRemaining;
    }

    /**
     * Set remaining tokens directly by calculating the required total tokens.
     */
    public function setRemainingTokens(int $remaining): void
    {
        $used = $this->getConsumedTokens();
        $total = $used + max(0, $remaining);

        Setting::updateOrCreate(['name' => 'ai_total_tokens'], ['value' => (string) $total]);
        Setting::updateOrCreate(['name' => 'ai_remaining_tokens'], ['value' => (string) max(0, $remaining)]);

        Cache::forget('openai_remaining_tokens');
        Cache::forget('openai_initial_tokens_pool');
    }

    /**
     * Set total tokens budget.
     */
    public function setTotalTokens(int $total): void
    {
        Setting::updateOrCreate(['name' => 'ai_total_tokens'], ['value' => (string) max(0, $total)]);

        Cache::forget('openai_remaining_tokens');
        Cache::forget('openai_initial_tokens_pool');
    }

    /**
     * Add additional tokens to the current budget.
     */
    public function addTokens(int $additional): void
    {
        $currentTotal = $this->getTotalAllocatedTokens();
        $newTotal = $currentTotal + max(0, $additional);

        $this->setTotalTokens($newTotal);
    }

    /**
     * Get the total allocated token pool from settings, env, or OpenAI rate-limit baseline.
     */
    public function getTotalAllocatedTokens(): int
    {
        // Priority 1: explicitly configured total tokens in settings
        $totalSetting = Setting::where('name', 'ai_total_tokens')->value('value');
        if ($totalSetting !== null && is_numeric($totalSetting)) {
            return (int) $totalSetting;
        }

        // Priority 2: config or env variable for total tokens
        $totalEnv = config('services.openai.total_tokens', env('OPENAI_TOTAL_TOKENS'));
        if ($totalEnv !== null && is_numeric($totalEnv)) {
            return (int) $totalEnv;
        }

        // Priority 3: legacy remaining_tokens setting or env (treated as baseline quota)
        $legacySetting = Setting::where('name', 'ai_remaining_tokens')->value('value');
        if ($legacySetting !== null && is_numeric($legacySetting)) {
            return (int) $legacySetting;
        }

        $legacyEnv = config('services.openai.remaining_tokens', env('OPENAI_REMAINING_TOKENS'));
        if ($legacyEnv !== null && is_numeric($legacyEnv)) {
            return (int) $legacyEnv;
        }

        // Priority 4: fetch baseline capacity from OpenAI API or default to 1,000,000
        return (int) Cache::remember('openai_initial_tokens_pool', self::CACHE_TTL_SECONDS, function (): int {
            return $this->fetchFromOpenAi();
        });
    }

    /**
     * Calculate tokens consumed across all channels from DailyToken.
     */
    public function getConsumedTokens(?int $userId = null, ?string $from = null, ?string $to = null): int
    {
        $query = DailyToken::query();

        if ($from) {
            $query->whereDate('date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('date', '<=', $to);
        }

        if ($userId !== null) {
            $query->where(function ($q) use ($userId) {
                $q->whereHas('whatsItem', fn ($sq) => $sq->where('user_id', $userId))
                    ->orWhereHas('messengerAccount', fn ($sq) => $sq->where('user_id', $userId))
                    ->orWhereHas('instagramItem', fn ($sq) => $sq->where('user_id', $userId))
                    ->orWhereHas('telegramAccount', fn ($sq) => $sq->where('user_id', $userId))
                    ->orWhereHas('tiktokAccount', fn ($sq) => $sq->where('user_id', $userId));
            });
        }

        return (int) $query->sum('tokens');
    }

    /**
     * Make a lightweight call to OpenAI API to inspect rate limit headers.
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

            if ($response->status() === 429) {
                $error = $response->json('error');
                $errorCode = is_array($error) ? ($error['code'] ?? null) : null;
                $errorType = is_array($error) ? ($error['type'] ?? null) : null;

                if ($errorCode === 'credit_balance_exhausted' || $errorType === 'insufficient_quota') {
                    return 0;
                }
            }

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

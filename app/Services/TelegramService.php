<?php

namespace App\Services;

use App\Models\DailyToken;
use App\Models\Setting;
use App\Models\TelegramAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;

class TelegramService
{
    protected string $apiBase = 'https://api.telegram.org';

    /**
     * Call the Telegram Bot API getMe endpoint to test token and fetch bot details.
     *
     * @return array{success: bool, data?: array<string, mixed>, message?: string}
     */
    public function getMe(string $botToken): array
    {
        try {
            $response = Http::timeout(10)
                ->get("{$this->apiBase}/bot{$botToken}/getMe");

            if (! $response->successful()) {
                $description = $response->json('description', 'Invalid bot token or failed request.');

                return [
                    'success' => false,
                    'message' => $description,
                ];
            }

            $result = $response->json('result', []);

            return [
                'success' => true,
                'data' => [
                    'id' => (string) ($result['id'] ?? ''),
                    'is_bot' => (bool) ($result['is_bot'] ?? true),
                    'first_name' => $result['first_name'] ?? '',
                    'username' => $result['username'] ?? '',
                    'can_join_groups' => (bool) ($result['can_join_groups'] ?? false),
                    'can_read_all_group_messages' => (bool) ($result['can_read_all_group_messages'] ?? false),
                    'supports_inline_queries' => (bool) ($result['supports_inline_queries'] ?? false),
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('TelegramService: getMe error: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to reach Telegram API: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Set the webhook URL for the bot with optional secret_token.
     *
     * @return array{success: bool, message?: string}
     */
    public function setWebhook(string $botToken, string $webhookUrl, ?string $secretToken = null): array
    {
        try {
            $payload = [
                'url' => $webhookUrl,
                'allowed_updates' => ['message'],
                'drop_pending_updates' => false,
            ];

            if (! empty($secretToken)) {
                $payload['secret_token'] = $secretToken;
            }

            $response = Http::timeout(15)
                ->post("{$this->apiBase}/bot{$botToken}/setWebhook", $payload);

            if (! $response->successful() || ! $response->json('ok')) {
                $description = $response->json('description', 'Failed to set webhook.');
                Log::warning("TelegramService: setWebhook failed for bot: {$description}");

                return [
                    'success' => false,
                    'message' => $description,
                ];
            }

            return [
                'success' => true,
                'message' => $response->json('description', 'Webhook set successfully.'),
            ];
        } catch (\Throwable $e) {
            Log::error('TelegramService: setWebhook error: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Failed to set Telegram webhook: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Delete the webhook from Telegram for this bot.
     *
     * @return array{success: bool, message?: string}
     */
    public function deleteWebhook(string $botToken): array
    {
        try {
            $response = Http::timeout(10)
                ->post("{$this->apiBase}/bot{$botToken}/deleteWebhook");

            return [
                'success' => $response->successful() && (bool) $response->json('ok'),
                'message' => $response->json('description', 'Webhook deleted successfully.'),
            ];
        } catch (\Throwable $e) {
            Log::warning('TelegramService: deleteWebhook error: '.$e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send a text message to a Telegram chat.
     *
     * @return array{success: bool, message_id?: int, raw?: array<string, mixed>, message?: string}
     */
    public function sendMessage(string $botToken, string|int $chatId, string $text): array
    {
        try {
            $response = Http::timeout(15)
                ->post("{$this->apiBase}/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                ]);

            if (! $response->successful() || ! $response->json('ok')) {
                $errorDesc = $response->json('description', 'Failed to send Telegram message.');
                Log::warning("TelegramService: sendMessage failed: {$errorDesc}");

                return [
                    'success' => false,
                    'message' => $errorDesc,
                    'raw' => $response->json(),
                ];
            }

            $messageId = $response->json('result.message_id');

            return [
                'success' => true,
                'message_id' => $messageId,
                'raw' => $response->json(),
            ];
        } catch (\Throwable $e) {
            Log::error('TelegramService: sendMessage error: '.$e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate AI reply for Telegram using the exact same prompt logic as Messenger.
     */
    public function generateAiReply(TelegramAccount $telegramAccount, string $userMessage): ?string
    {
        $aiContext = ! empty($telegramAccount->ai_context)
            ? $telegramAccount->ai_context
            : (Setting::firstWhere('name', 'ai_context')?->value ?? 'أنت موظف خدمة عملاء، ردّ بأسلوب ودي وبسيط.');

        $fileContent = $this->resolveAiFileContent($telegramAccount->ai_file);

        $fileDataSection = '';
        if (! empty($fileContent)) {
            $fileDataSection = "\n\nبيانات وقائمة المنتجات / الخدمات والمعلومات المتاحة:\n".$fileContent;
        }

        $linksSection = '';
        if ($telegramAccount->android_link || $telegramAccount->ios_link || $telegramAccount->website_url) {
            $linksSection = "\n\nروابط وتفاصيل الطلب المتاحة:";
            if ($telegramAccount->website_url) {
                $linksSection .= "\n- الموقع الإلكتروني: {$telegramAccount->website_url}";
            }
            if ($telegramAccount->android_link) {
                $linksSection .= "\n- تطبيق أندرويد (Android): {$telegramAccount->android_link}";
            }
            if ($telegramAccount->ios_link) {
                $linksSection .= "\n- تطبيق آيفون (iOS): {$telegramAccount->ios_link}";
            }
        }

        $instructions = <<<PROMPT
        {$aiContext}
        {$fileDataSection}
        {$linksSection}

        التعليمات:
        - الرد باللغة العربية فقط بأسلوب مهذب ومساعد وموجز ومحترم.
        - اعتمد على البيانات المذكورة أعلاه في الرد على استفسارات العميل ولا تخترع أي معلومات أو أسعار غير موجودة.
        - إذا سأل العميل عن شيء غير مذكور في البيانات أو غير متاح، أخبره بلباقة أنه غير متوفر حالياً.
        - أول ما يطلب العميل (عندما يريد طلب، يسأل كيف يطلب، يريد عمل أوردر، أو يطلب أي صنف أو وجبة): يجب الرد عليه بأسلوب مهذب ومحترم وإخباره: «تقدر تطلب من هنا» مع إرسال روابط الطلب المتوفرة (الموقع الإلكتروني، تطبيق أندرويد، وتطبيق iOS) المذكورة أعلاه.
        - في حال عدم توفر روابط طلب أعلاه، أخبر العميل بلباقة أنه يمكنه كتابة طلبه وتفاصيله هنا لمساعدته.
        PROMPT;

        try {
            $model = env('OPENAI_MODEL', 'gpt-4o-mini');

            $response = OpenAI::responses()->create([
                'model' => $model,
                'instructions' => $instructions,
                'input' => $userMessage,
            ]);

            $totalTokens = (int) ($response->usage?->totalTokens ?? 0);
            if ($totalTokens > 0) {
                DailyToken::recordForTelegram($telegramAccount->id, $totalTokens);
            }

            $reply = trim((string) ($response->outputText ?? ''));

            if (! empty($reply) && $this->isOrderIntent($userMessage)) {
                $hasLink = ($telegramAccount->website_url && str_contains($reply, $telegramAccount->website_url))
                    || ($telegramAccount->android_link && str_contains($reply, $telegramAccount->android_link))
                    || ($telegramAccount->ios_link && str_contains($reply, $telegramAccount->ios_link));

                if (! $hasLink) {
                    $orderLinksMsg = $this->formatOrderingLinksMessage($telegramAccount->website_url, $telegramAccount->android_link, $telegramAccount->ios_link);
                    if ($orderLinksMsg) {
                        $reply .= "\n\n{$orderLinksMsg}";
                    }
                }
            }

            return $reply ?: null;
        } catch (\Throwable $e) {
            Log::warning('OpenAI generateAiReply for Telegram fallback triggered: '.$e->getMessage());

            $fallback = 'أهلاً بك! نسعد بخدمتك.';
            $orderLinksMsg = $this->formatOrderingLinksMessage($telegramAccount->website_url, $telegramAccount->android_link, $telegramAccount->ios_link);
            if ($orderLinksMsg) {
                $fallback .= "\n{$orderLinksMsg}";
            } else {
                $fallback .= ' يمكنك طرح استفسارك أو طلبك مباشرة، وسنكون سعداء بمساعدتك.';
            }

            return $fallback;
        }
    }

    /**
     * Resolve and read text content from ai_file.
     */
    public function resolveAiFileContent(?string $aiFile): ?string
    {
        if (empty($aiFile)) {
            return null;
        }

        $trimmed = trim($aiFile);

        if (str_contains($trimmed, "\n") || str_contains($trimmed, "\r")) {
            return $trimmed;
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            try {
                $response = Http::timeout(5)->get($trimmed);
                if ($response->successful()) {
                    return $response->body();
                }
            } catch (\Throwable $e) {
                Log::warning("TelegramService: Could not fetch ai_file from URL: {$trimmed} — ".$e->getMessage());
            }

            return null;
        }

        try {
            if (@file_exists($trimmed) && @is_file($trimmed)) {
                return @file_get_contents($trimmed);
            }

            $storageApp = storage_path('app/'.$trimmed);
            if (@file_exists($storageApp) && @is_file($storageApp)) {
                return @file_get_contents($storageApp);
            }

            $storagePublic = storage_path('app/public/'.$trimmed);
            if (@file_exists($storagePublic) && @is_file($storagePublic)) {
                return @file_get_contents($storagePublic);
            }

            $publicPath = public_path($trimmed);
            if (@file_exists($publicPath) && @is_file($publicPath)) {
                return @file_get_contents($publicPath);
            }

            if (Storage::exists($trimmed)) {
                return Storage::get($trimmed);
            }
        } catch (\Throwable $e) {
            Log::info("TelegramService: Could not resolve ai_file as path: {$trimmed}");
        }

        return null;
    }

    /**
     * Check if the customer message expresses an intent to order.
     */
    public function isOrderIntent(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));

        $keywords = [
            'اطلب', 'أطلب', 'طلب', 'اوردر', 'أوردر', 'order',
            'دليفري', 'توصيل', 'شراء', 'احجز', 'أحجز', 'حجز',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format a polite ordering message with the available links.
     */
    public function formatOrderingLinksMessage(?string $websiteUrl, ?string $androidLink, ?string $iosLink): ?string
    {
        if (! $websiteUrl && ! $androidLink && ! $iosLink) {
            return null;
        }

        $lines = ['تقدر تطلب من هنا:'];

        if ($websiteUrl) {
            $lines[] = "الموقع الإلكتروني: {$websiteUrl}";
        }
        if ($androidLink) {
            $lines[] = "تطبيق أندرويد: {$androidLink}";
        }
        if ($iosLink) {
            $lines[] = "تطبيق iOS: {$iosLink}";
        }

        return implode("\n", $lines);
    }
}

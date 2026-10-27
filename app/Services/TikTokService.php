<?php

namespace App\Services;

use App\Models\DailyToken;
use App\Models\Setting;
use App\Models\TikTokAccount;
use App\trait\FormatsChatLinks;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;

class TikTokService
{
    use FormatsChatLinks;

    protected string $clientKey;

    protected string $clientSecret;

    protected string $defaultRedirectUri;

    public function __construct()
    {
        $this->clientKey = (string) config('services.tiktok.client_key', env('TIKTOK_CLIENT_KEY', ''));
        $this->clientSecret = (string) config('services.tiktok.client_secret', env('TIKTOK_CLIENT_SECRET', ''));
        $this->defaultRedirectUri = (string) config('services.tiktok.redirect_uri', env('TIKTOK_REDIRECT_URI', ''));
    }

    /**
     * Generate TikTok OAuth 2.0 authorization URL.
     */
    public function getAuthorizationUrl(string $state, ?string $redirectUri = null): string
    {
        $redirect = $redirectUri ?: $this->defaultRedirectUri;
        $scopes = 'user.info.basic,video.list,comment.list,comment.list.manage,business.messages';

        $query = http_build_query([
            'client_key' => $this->clientKey,
            'scope' => $scopes,
            'response_type' => 'code',
            'redirect_uri' => $redirect,
            'state' => $state,
        ]);

        return "https://www.tiktok.com/v2/auth/authorize/?{$query}";
    }

    /**
     * Exchange authorization code for access and refresh tokens.
     *
     * @return array{success: bool, data?: array, message?: string}
     */
    public function requestAccessToken(string $code, ?string $redirectUri = null): array
    {
        $redirect = $redirectUri ?: $this->defaultRedirectUri;

        try {
            $response = Http::asForm()->timeout(15)->post('https://open.tiktokapis.com/v2/oauth/token/', [
                'client_key' => $this->clientKey,
                'client_secret' => $this->clientSecret,
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirect,
            ]);

            $json = $response->json();

            if ($response->successful() && ! empty($json['data']['access_token'])) {
                return [
                    'success' => true,
                    'data' => $json['data'],
                ];
            }

            return [
                'success' => false,
                'message' => $json['error']['message'] ?? ($json['message'] ?? 'Failed to exchange TikTok code for tokens.'),
                'data' => $json,
            ];
        } catch (\Throwable $e) {
            Log::error('TikTokService requestAccessToken Exception: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Network error connecting to TikTok API: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Refresh access token using the account refresh token.
     *
     * @return array{success: bool, data?: array, message?: string}
     */
    public function refreshAccessToken(TikTokAccount $account): array
    {
        if (empty($account->refresh_token)) {
            return [
                'success' => false,
                'message' => 'No refresh token available for this TikTok account.',
            ];
        }

        try {
            $response = Http::asForm()->timeout(15)->post('https://open.tiktokapis.com/v2/oauth/token/', [
                'client_key' => $this->clientKey,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
            ]);

            $json = $response->json();

            if ($response->successful() && ! empty($json['data']['access_token'])) {
                $data = $json['data'];
                $expiresIn = (int) ($data['expires_in'] ?? 86400);
                $refreshExpiresIn = (int) ($data['refresh_expires_in'] ?? 31536000);

                $account->update([
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? $account->refresh_token,
                    'token_expires_at' => now()->addSeconds($expiresIn),
                    'refresh_token_expires_at' => now()->addSeconds($refreshExpiresIn),
                ]);

                return [
                    'success' => true,
                    'data' => $data,
                ];
            }

            return [
                'success' => false,
                'message' => $json['error']['message'] ?? 'Failed to refresh TikTok access token.',
            ];
        } catch (\Throwable $e) {
            Log::error('TikTokService refreshAccessToken Exception: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Network error refreshing token: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Ensure the account has a valid, non-expired access token.
     */
    public function ensureValidAccessToken(TikTokAccount $account): string
    {
        if ($account->token_expires_at && $account->token_expires_at->isPast()) {
            $this->refreshAccessToken($account);
            $account->refresh();
        }

        return (string) $account->access_token;
    }

    /**
     * Fetch user / business info from TikTok.
     *
     * @return array{success: bool, data?: array, message?: string}
     */
    public function getUserInfo(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->get('https://open.tiktokapis.com/v2/user/info/', [
                    'fields' => 'open_id,union_id,avatar_url,display_name,username',
                ]);

            $json = $response->json();

            if ($response->successful() && ! empty($json['data']['user'])) {
                return [
                    'success' => true,
                    'data' => $json['data']['user'],
                ];
            }

            return [
                'success' => false,
                'message' => $json['error']['message'] ?? 'Could not fetch TikTok user info.',
            ];
        } catch (\Throwable $e) {
            Log::error('TikTokService getUserInfo Exception: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Error fetching TikTok user info: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Reply to a TikTok video comment.
     *
     * @return array{success: bool, data?: array, message?: string}
     */
    public function replyToComment(TikTokAccount $account, string $commentId, string $message): array
    {
        $token = $this->ensureValidAccessToken($account);

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->post('https://open.tiktokapis.com/v2/comment/reply/', [
                    'comment_id' => $commentId,
                    'text' => $message,
                ]);

            $json = $response->json();

            if ($response->successful() && ($json['error']['code'] ?? '') === 'ok') {
                return [
                    'success' => true,
                    'data' => $json['data'] ?? [],
                ];
            }

            // Fallback for business research/comment endpoint format
            if ($response->successful() && isset($json['data'])) {
                return [
                    'success' => true,
                    'data' => $json['data'],
                ];
            }

            return [
                'success' => false,
                'message' => $json['error']['message'] ?? ($json['message'] ?? 'Failed to reply to TikTok comment.'),
                'data' => $json,
            ];
        } catch (\Throwable $e) {
            Log::error('TikTokService replyToComment Exception: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Network error replying to TikTok comment: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Send direct message to a TikTok customer via Direct Message API.
     *
     * @return array{success: bool, message_id?: string, data?: array, message?: string}
     */
    public function sendDirectMessage(TikTokAccount $account, string $recipientOpenId, string $message): array
    {
        $token = $this->ensureValidAccessToken($account);

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->post('https://open.tiktokapis.com/v2/im/message/send/', [
                    'recipient' => [
                        'open_id' => $recipientOpenId,
                    ],
                    'message' => [
                        'text' => $message,
                    ],
                ]);

            $json = $response->json();

            if ($response->successful() && (($json['error']['code'] ?? '') === 'ok' || isset($json['data']))) {
                $messageId = (string) ($json['data']['message_id'] ?? ('tt_'.uniqid()));

                return [
                    'success' => true,
                    'message_id' => $messageId,
                    'data' => $json['data'] ?? [],
                ];
            }

            return [
                'success' => false,
                'message' => $json['error']['message'] ?? ($json['message'] ?? 'Failed to send TikTok direct message.'),
                'data' => $json,
            ];
        } catch (\Throwable $e) {
            Log::error('TikTokService sendDirectMessage Exception: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Network error sending TikTok direct message: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Verify webhook signature from TikTok.
     */
    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader, ?string $timestampHeader, ?string $secret = null): bool
    {
        $appSecret = $secret ?: $this->clientSecret;
        if (empty($appSecret) || empty($signatureHeader)) {
            return false;
        }

        $calculated = hash_hmac('sha256', ($timestampHeader ?? '').$rawPayload, $appSecret);

        $cleanHeader = str_replace('sha256=', '', $signatureHeader);

        return hash_equals($calculated, $cleanHeader);
    }

    /**
     * Generate AI reply for an incoming message or comment on TikTok.
     */
    public function generateAiReply(TikTokAccount $account, string $userMessage): ?string
    {
        $aiContext = ! empty($account->ai_context)
            ? $account->ai_context
            : (Setting::firstWhere('name', 'ai_context')?->value ?? 'أنت موظف خدمة عملاء، ردّ بأسلوب ودي وبسيط.');

        $fileContent = $this->resolveAiFileContent($account->ai_file);

        $fileDataSection = '';
        if (! empty($fileContent)) {
            $fileDataSection = "\n\nبيانات وقائمة المنتجات / الخدمات والمعلومات المتاحة:\n".$fileContent;
        }

        $linksSection = $this->buildLinksPromptSection(
            $account->website_url,
            $account->android_link,
            $account->ios_link
        );

        $linkInstructions = $this->getLinkPromptInstructions();

        $instructions = <<<PROMPT
        {$aiContext}
        {$fileDataSection}
        {$linksSection}

        التعليمات:
        - الرد باللغة العربية فقط بأسلوب مهذب ومساعد وموجز ومحترم ومناسب لمنصة تيك توك.
        - اعتمد على البيانات المذكورة أعلاه في الرد على استفسارات العميل ولا تخترع أي معلومات أو أسعار غير موجودة.
        - إذا سأل العميل عن شيء غير مذكور في البيانات أو غير متاح، أخبره بلباقة أنه غير متوفر حالياً.
        - أول ما يطلب العميل (عندما يريد طلب، يسأل كيف يطلب، يريد عمل أوردر، أو يطلب أي صنف أو وجبة): يجب الرد عليه بأسلوب مهذب ومحترم وإخباره: «تقدر تطلب من هنا» مع إرسال روابط الطلب المباشرة المذكورة أعلاه.
        - في حال عدم توفر روابط طلب أعلاه، أخبر العميل بلباقة أنه يمكنه كتابة طلبه وتفاصيله هنا لمساعدته.
        {$linkInstructions}
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
                DailyToken::recordForTikTok($account->id, $totalTokens);
            }

            $reply = trim((string) ($response->outputText ?? ''));

            if (! empty($reply) && $this->isOrderIntent($userMessage)) {
                $hasLink = ($account->website_url && str_contains($reply, $account->website_url))
                    || ($account->android_link && str_contains($reply, $account->android_link))
                    || ($account->ios_link && str_contains($reply, $account->ios_link));

                if (! @$hasLink) {
                    $orderLinksMsg = $this->formatOrderingLinksMessage($account->website_url, $account->android_link, $account->ios_link);
                    if ($orderLinksMsg) {
                        $reply .= "\n\n{$orderLinksMsg}";
                    }
                }
            }

            return $reply ? $this->cleanChatLinks($reply) : null;
        } catch (\Throwable $e) {
            Log::warning('OpenAI generateAiReply for TikTok fallback triggered: '.$e->getMessage());

            $fallback = 'أهلاً بك! نسعد بخدمتك.';
            $orderLinksMsg = $this->formatOrderingLinksMessage($account->website_url, $account->android_link, $account->ios_link);
            if ($orderLinksMsg) {
                $fallback .= "\n{$orderLinksMsg}";
            } else {
                $fallback .= ' يمكنك طرح استفسارك أو طلبك مباشرة، وسنكون سعداء بمساعدتك.';
            }

            return $this->cleanChatLinks($fallback);
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
                Log::warning("TikTokService: Could not fetch ai_file from URL: {$trimmed} — ".$e->getMessage());
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
            Log::info("TikTokService: Could not resolve ai_file as path: {$trimmed}");
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
}

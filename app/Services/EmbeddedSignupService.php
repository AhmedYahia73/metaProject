<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmbeddedSignupService
{
    protected string $baseUrl;

    protected string $appId;

    protected string $appSecret;

    protected string $systemUserToken;

    public function __construct()
    {
        $version = config('services.meta.graph_version', 'v21.0');
        $this->baseUrl = "https://graph.facebook.com/{$version}";
        $this->appId = (string) config('services.meta.app_id', '');
        $this->appSecret = (string) config('services.meta.app_secret', '');
        $this->systemUserToken = (string) config('services.meta.system_user_token', '');
    }

    /**
     * Exchange the short-lived code from Embedded Signup for a long-lived user access token,
     * then upgrade it to a System User token via the Business Manager.
     *
     * @return array{success: bool, access_token?: string, token_type?: string, message?: string}
     */
    public function exchangeCodeForToken(string $code): array
    {
        if (empty($this->appId) || empty($this->appSecret)) {
            return [
                'success' => false,
                'message' => 'META_APP_ID or META_APP_SECRET not configured in .env.',
            ];
        }

        $response = Http::get("{$this->baseUrl}/oauth/access_token", [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'code' => $code,
        ]);

        if (! $response->successful()) {
            $error = $response->json('error', []);
            Log::error('EmbeddedSignup: Failed to exchange code for token', ['error' => $error]);

            return [
                'success' => false,
                'message' => $error['message'] ?? 'Failed to exchange code for access token.',
                'raw' => $response->json(),
            ];
        }

        $data = $response->json();
        Log::info('EmbeddedSignup: Code exchanged for token successfully', [
            'token_type' => $data['token_type'] ?? null,
        ]);

        return [
            'success' => true,
            'access_token' => $data['access_token'],
            'token_type' => $data['token_type'] ?? 'bearer',
        ];
    }

    /**
     * Fetch all WABA IDs and Phone Number IDs the customer granted access to.
     *
     * Meta returns `granular_scopes` inside the token debug info.
     * We look for whatsapp_business_management scope entries.
     *
     * @return array{waba_id: string, phone_number_ids: string[]}[]
     */
    public function getGrantedWabaDetails(string $userAccessToken): array
    {
        // Inspect the token to get granted WABAs
        $response = Http::get("{$this->baseUrl}/debug_token", [
            'input_token' => $userAccessToken,
            'access_token' => "{$this->appId}|{$this->appSecret}",
        ]);

        if (! $response->successful()) {
            Log::error('EmbeddedSignup: debug_token failed', ['error' => $response->json()]);

            return [];
        }

        $data = $response->json('data', []);
        $granularScopes = $data['granular_scopes'] ?? [];

        $results = [];

        foreach ($granularScopes as $scope) {
            if ($scope['scope'] === 'whatsapp_business_management' && ! empty($scope['target_ids'])) {
                foreach ($scope['target_ids'] as $wabaId) {
                    $phoneNumberIds = $this->getPhoneNumberIdsForWaba($wabaId, $userAccessToken);
                    $results[] = [
                        'waba_id' => $wabaId,
                        'phone_number_ids' => $phoneNumberIds,
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Fetch all phone number IDs under a customer's WABA using their token.
     *
     * @return string[]
     */
    public function getPhoneNumberIdsForWaba(string $wabaId, string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get("{$this->baseUrl}/{$wabaId}/phone_numbers", [
                'fields' => 'id,display_phone_number,verified_name,code_verification_status,status',
            ]);

        if (! $response->successful()) {
            Log::error('EmbeddedSignup: Failed to get phone numbers for WABA', [
                'waba_id' => $wabaId,
                'error' => $response->json('error'),
            ]);

            return [];
        }

        return collect($response->json('data', []))->pluck('id')->all();
    }

    /**
     * Fetch details of a single phone number using the customer's token.
     *
     * @return array<string, mixed>|null
     */
    public function getPhoneNumberDetails(string $phoneNumberId, string $accessToken): ?array
    {
        $response = Http::withToken($accessToken)
            ->get("{$this->baseUrl}/{$phoneNumberId}", [
                'fields' => 'id,display_phone_number,verified_name,code_verification_status,status,quality_rating',
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        Log::warning('EmbeddedSignup: Failed to get phone number details', [
            'phone_number_id' => $phoneNumberId,
            'error' => $response->json('error'),
        ]);

        return null;
    }

    /**
     * Subscribe our App to the customer's WABA so the App-level webhook
     * receives messages from their phone numbers.
     *
     * POST /{waba-id}/subscribed_apps
     */
    public function subscribeAppToWaba(string $wabaId, string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->post("{$this->baseUrl}/{$wabaId}/subscribed_apps");

        if ($response->successful()) {
            Log::info('EmbeddedSignup: App subscribed to WABA webhook', ['waba_id' => $wabaId]);

            return ['success' => true];
        }

        $error = $response->json('error', []);
        Log::error('EmbeddedSignup: Failed to subscribe app to WABA', [
            'waba_id' => $wabaId,
            'error' => $error,
        ]);

        return [
            'success' => false,
            'message' => $error['message'] ?? 'Failed to subscribe app to WABA.',
            'raw' => $response->json(),
        ];
    }

    /**
     * Register a phone number on the WhatsApp Cloud API using the customer's token.
     * Required only if the number hasn't been registered yet.
     *
     * POST /{phone-number-id}/register
     */
    public function registerPhoneNumber(string $phoneNumberId, string $accessToken, string $pin = '000000'): array
    {
        $response = Http::withToken($accessToken)
            ->post("{$this->baseUrl}/{$phoneNumberId}/register", [
                'messaging_product' => 'whatsapp',
                'pin' => $pin,
            ]);

        if ($response->successful()) {
            Log::info('EmbeddedSignup: Phone number registered on Cloud API', [
                'phone_number_id' => $phoneNumberId,
            ]);

            return ['success' => true];
        }

        $error = $response->json('error', []);
        Log::warning('EmbeddedSignup: Phone number registration failed (may already be registered)', [
            'phone_number_id' => $phoneNumberId,
            'error' => $error,
        ]);

        return [
            'success' => false,
            'message' => $error['message'] ?? 'Failed to register phone number.',
            'code' => $error['code'] ?? null,
            'raw' => $response->json(),
        ];
    }

    /**
     * Send a WhatsApp text message using the customer's own token.
     */
    public function sendTextMessage(
        string $accessToken,
        string $phoneNumberId,
        string $to,
        string $body,
    ): bool {
        $response = Http::withToken($accessToken)
            ->post("{$this->baseUrl}/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $body, 'preview_url' => false],
            ]);

        if ($response->successful()) {
            return true;
        }

        Log::error('EmbeddedSignup: Failed to send WhatsApp message', [
            'phone_number_id' => $phoneNumberId,
            'to' => $to,
            'error' => $response->json('error'),
        ]);

        return false;
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaWhatsAppService
{
    protected string $baseUrl;

    protected ?string $wabaId;

    protected ?string $systemUserToken;

    public function __construct()
    {
        $version = config('services.meta.graph_version', 'v21.0');
        $this->baseUrl = "https://graph.facebook.com/{$version}";
        $this->wabaId = config('services.meta.waba_id');
        $this->systemUserToken = config('services.meta.system_user_token');
    }

    /**
     * Check if Meta credentials are fully configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->wabaId) && ! empty($this->systemUserToken);
    }

    /**
     * Get the default system user permanent token.
     */
    public function getSystemUserToken(): ?string
    {
        return $this->systemUserToken;
    }

    /**
     * Get the configured WhatsApp Business Account ID.
     */
    public function getWabaId(): ?string
    {
        return $this->wabaId;
    }

    /**
     * Normalize and split a phone number into country code (cc) and national number.
     *
     * @return array{cc: string, phone_number: string, full: string}
     */
    public function parsePhoneNumber(string $phone): array
    {
        // Remove spaces, dashes, parentheses
        $clean = preg_replace('/[^0-9]/', '', $phone);

        // Strip leading zeros if they represent international prefix 00
        if (str_starts_with($clean, '00')) {
            $clean = substr($clean, 2);
        }

        // Common Arab countries detection
        // Egypt (20)
        if (str_starts_with($clean, '20') && strlen($clean) === 12) {
            return [
                'cc' => '20',
                'phone_number' => substr($clean, 2),
                'full' => '+'.$clean,
            ];
        }

        // Egypt local format: 010..., 011..., 012..., 015...
        if (preg_match('/^0(1[0125][0-9]{8})$/', $clean, $matches)) {
            return [
                'cc' => '20',
                'phone_number' => $matches[1],
                'full' => '+20'.$matches[1],
            ];
        }

        // Saudi Arabia (966): 9665xxxxxxxx or 05xxxxxxxx
        if (str_starts_with($clean, '966') && strlen($clean) === 12) {
            return [
                'cc' => '966',
                'phone_number' => substr($clean, 3),
                'full' => '+'.$clean,
            ];
        }
        if (preg_match('/^0(5[0-9]{8})$/', $clean, $matches)) {
            return [
                'cc' => '966',
                'phone_number' => $matches[1],
                'full' => '+966'.$matches[1],
            ];
        }

        // UAE (971): 9715xxxxxxxx or 05xxxxxxxx
        if (str_starts_with($clean, '971') && strlen($clean) >= 11) {
            return [
                'cc' => '971',
                'phone_number' => substr($clean, 3),
                'full' => '+'.$clean,
            ];
        }

        // Fallback: Default to Egypt cc (20) if starts with 0
        if (str_starts_with($clean, '0')) {
            $national = ltrim($clean, '0');

            return [
                'cc' => '20',
                'phone_number' => $national,
                'full' => '+20'.$national,
            ];
        }

        // General fallback: first 2 digits as CC, rest as number
        $cc = substr($clean, 0, 2);
        $number = substr($clean, 2);

        return [
            'cc' => $cc,
            'phone_number' => $number,
            'full' => '+'.$clean,
        ];
    }

    /**
     * Add a phone number to the WhatsApp Business Account.
     * Checks first if the number already exists in WABA to avoid duplicates.
     */
    public function addPhoneNumber(string $phone, string $verifiedName, ?string $wabaId = null): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Meta credentials are not configured in .env (META_WABA_ID or META_SYSTEM_USER_TOKEN missing).',
            ];
        }

        $waba = $wabaId ?: $this->wabaId;
        $parsed = $this->parsePhoneNumber($phone);

        // 1. Check if already exists in WABA
        $existing = $this->findPhoneNumberInWaba($parsed['phone_number'], $waba);
        if ($existing && ! empty($existing['id'])) {
            Log::info('MetaWhatsApp: Phone number already exists in WABA', [
                'phone' => $phone,
                'phone_number_id' => $existing['id'],
            ]);

            return [
                'success' => true,
                'already_exists' => true,
                'phone_number_id' => $existing['id'],
                'data' => $existing,
            ];
        }

        // 2. Add phone number via Meta Graph API
        $response = Http::withToken($this->systemUserToken)
            ->post("{$this->baseUrl}/{$waba}/phone_numbers", [
                'cc' => $parsed['cc'],
                'phone_number' => $parsed['phone_number'],
                'verified_name' => $verifiedName,
            ]);

        if ($response->successful()) {
            $data = $response->json();
            Log::info('MetaWhatsApp: Phone number added successfully', [
                'phone' => $phone,
                'response' => $data,
            ]);

            return [
                'success' => true,
                'already_exists' => false,
                'phone_number_id' => $data['id'] ?? null,
                'data' => $data,
            ];
        }

        $error = $response->json()['error'] ?? [];
        Log::error('MetaWhatsApp: Failed to add phone number', [
            'phone' => $phone,
            'status' => $response->status(),
            'error' => $error,
        ]);

        return [
            'success' => false,
            'message' => $error['message'] ?? 'Failed to add phone number to Meta WABA.',
            'error_code' => $error['code'] ?? null,
            'error_subcode' => $error['error_subcode'] ?? null,
            'raw' => $response->json(),
        ];
    }

    /**
     * Request verification code (OTP) via SMS or VOICE.
     *
     * @param  string  $codeMethod  'SMS' or 'VOICE'
     */
    public function requestCode(string $phoneNumberId, string $codeMethod = 'SMS', string $language = 'ar'): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Meta credentials are not configured in .env.',
            ];
        }

        $response = Http::withToken($this->systemUserToken)
            ->post("{$this->baseUrl}/{$phoneNumberId}/request_code", [
                'code_method' => strtoupper($codeMethod),
                'language' => $language,
            ]);

        if ($response->successful()) {
            return [
                'success' => true,
                'message' => "Verification code sent via {$codeMethod}.",
                'data' => $response->json(),
            ];
        }

        $error = $response->json()['error'] ?? [];
        Log::error('MetaWhatsApp: Request code failed', [
            'phone_number_id' => $phoneNumberId,
            'error' => $error,
        ]);

        return [
            'success' => false,
            'message' => $error['message'] ?? 'Failed to request verification code from Meta.',
            'raw' => $response->json(),
        ];
    }

    /**
     * Verify the received OTP code with Meta.
     */
    public function verifyCode(string $phoneNumberId, string $code): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Meta credentials are not configured in .env.',
            ];
        }

        $response = Http::withToken($this->systemUserToken)
            ->post("{$this->baseUrl}/{$phoneNumberId}/verify_code", [
                'code' => trim($code),
            ]);

        if ($response->successful()) {
            return [
                'success' => true,
                'message' => 'Phone number verified successfully.',
                'data' => $response->json(),
            ];
        }

        $error = $response->json()['error'] ?? [];
        Log::error('MetaWhatsApp: Verify code failed', [
            'phone_number_id' => $phoneNumberId,
            'error' => $error,
        ]);

        return [
            'success' => false,
            'message' => $error['message'] ?? 'Invalid or expired verification code.',
            'raw' => $response->json(),
        ];
    }

    /**
     * Register phone number to WhatsApp Cloud API with a 6-digit PIN.
     *
     * @param  string  $pin  6-digit two-step verification PIN
     */
    public function registerNumber(string $phoneNumberId, string $pin): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Meta credentials are not configured in .env.',
            ];
        }

        $response = Http::withToken($this->systemUserToken)
            ->post("{$this->baseUrl}/{$phoneNumberId}/register", [
                'messaging_product' => 'whatsapp',
                'pin' => trim($pin),
            ]);

        if ($response->successful()) {
            return [
                'success' => true,
                'message' => 'Phone number registered to WhatsApp Cloud API successfully.',
                'data' => $response->json(),
            ];
        }

        $error = $response->json()['error'] ?? [];
        Log::error('MetaWhatsApp: Register number failed', [
            'phone_number_id' => $phoneNumberId,
            'error' => $error,
        ]);

        return [
            'success' => false,
            'message' => $error['message'] ?? 'Failed to register phone number on WhatsApp Cloud API.',
            'raw' => $response->json(),
        ];
    }

    /**
     * Fetch phone numbers under a WABA.
     */
    public function getWabaPhoneNumbers(?string $wabaId = null): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $waba = $wabaId ?: $this->wabaId;

        $response = Http::withToken($this->systemUserToken)
            ->get("{$this->baseUrl}/{$waba}/phone_numbers", [
                'fields' => 'id,display_phone_number,verified_name,quality_rating,code_verification_status,status',
            ]);

        if ($response->successful()) {
            return $response->json()['data'] ?? [];
        }

        return [];
    }

    /**
     * Find a phone number in WABA list by matching digits.
     */
    public function findPhoneNumberInWaba(string $phoneDigits, ?string $wabaId = null): ?array
    {
        $cleanSearch = preg_replace('/[^0-9]/', '', $phoneDigits);
        $numbers = $this->getWabaPhoneNumbers($wabaId);

        foreach ($numbers as $num) {
            $cleanDisplay = preg_replace('/[^0-9]/', '', $num['display_phone_number'] ?? '');
            if ($cleanDisplay === $cleanSearch || str_ends_with($cleanDisplay, $cleanSearch) || str_ends_with($cleanSearch, $cleanDisplay)) {
                return $num;
            }
        }

        return null;
    }

    /**
     * Get phone number details by phone_number_id.
     */
    public function getPhoneNumberDetails(string $phoneNumberId): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Meta credentials are not configured.',
            ];
        }

        $response = Http::withToken($this->systemUserToken)
            ->get("{$this->baseUrl}/{$phoneNumberId}", [
                'fields' => 'id,display_phone_number,verified_name,code_verification_status,status,quality_rating',
            ]);

        if ($response->successful()) {
            return [
                'success' => true,
                'data' => $response->json(),
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to fetch phone number details from Meta.',
            'raw' => $response->json(),
        ];
    }
}

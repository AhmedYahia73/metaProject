<?php

namespace App\Services;

use App\Models\EcommerceOrder;
use App\Models\MsgSend;
use App\Models\WhatsItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaWhatsAppService
{
    protected string $baseUrl;

    protected ?string $wabaId;

    protected ?string $phoneNumberId;

    protected ?string $systemUserId;

    protected ?string $systemUserToken;

    protected ?string $configurationId;

    public function __construct()
    {
        $version = config('services.meta.graph_version', 'v21.0');
        $this->baseUrl = "https://graph.facebook.com/{$version}";
        $this->wabaId = config('services.meta.waba_id');
        $this->phoneNumberId = config('services.meta.phone_number_id');
        $this->systemUserId = config('services.meta.system_user_id');
        $this->systemUserToken = config('services.meta.system_user_token');
        $this->configurationId = config('services.meta.configuration_id');
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
     * Get the default Phone Number ID from env.
     */
    public function getPhoneNumberId(): ?string
    {
        return $this->phoneNumberId;
    }

    /**
     * Get the System User ID from env.
     */
    public function getSystemUserId(): ?string
    {
        return $this->systemUserId;
    }

    /**
     * Get the App Configuration ID from env.
     */
    public function getConfigurationId(): ?string
    {
        return $this->configurationId;
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
    public function addPhoneNumber(string $phone, ?string $verifiedName = null, ?string $wabaId = null): array
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

        $displayName = trim((string) $verifiedName);
        if ($displayName === '') {
            $displayName = 'Restaurant';
        }

        // 2. Add phone number via Meta Graph API
        $response = Http::withToken($this->systemUserToken)
            ->post("{$this->baseUrl}/{$waba}/phone_numbers", [
                'cc' => $parsed['cc'],
                'phone_number' => $parsed['phone_number'],
                'verified_name' => $displayName,
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
        $detailedMsg = $error['error_user_msg']
            ?? $error['error_data']['details']
            ?? $error['message']
            ?? 'Failed to add phone number to Meta WABA.';

        Log::error('MetaWhatsApp: Failed to add phone number', [
            'phone' => $phone,
            'status' => $response->status(),
            'error' => $error,
        ]);

        return [
            'success' => false,
            'message' => $detailedMsg,
            'error_message' => $error['message'] ?? 'Failed to add phone number to Meta WABA.',
            'error_code' => $error['code'] ?? null,
            'error_subcode' => $error['error_subcode'] ?? null,
            'error_user_title' => $error['error_user_title'] ?? null,
            'error_user_msg' => $error['error_user_msg'] ?? null,
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
    public function registerNumber(string $phoneNumberId, string $pin, ?string $token = null): array
    {
        $accessToken = ! empty($token) ? $token : $this->systemUserToken;

        if (empty($accessToken)) {
            return [
                'success' => false,
                'message' => 'Meta credentials are not configured in .env.',
            ];
        }

        $response = Http::withToken($accessToken)
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
     * Deregister a phone number from Meta WhatsApp Cloud API.
     * Removes the number from the Meta-hosted system, freeing it up for regular WhatsApp or re-registration.
     *
     * POST /{phone-number-id}/deregister
     */
    public function deregisterNumber(string $phoneNumberId, ?string $token = null): array
    {
        $accessToken = ! empty($token) ? $token : $this->systemUserToken;

        if (empty($accessToken)) {
            return [
                'success' => false,
                'message' => 'Meta credentials are not configured in .env.',
            ];
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->post("{$this->baseUrl}/{$phoneNumberId}/deregister");

            if ($response->successful()) {
                Log::info('MetaWhatsApp: Phone number deregistered from Meta successfully', [
                    'phone_number_id' => $phoneNumberId,
                ]);

                return [
                    'success' => true,
                    'message' => 'Phone number deregistered from Meta successfully.',
                    'data' => $response->json(),
                ];
            }

            $error = $response->json()['error'] ?? [];
            Log::warning('MetaWhatsApp: Deregister number failed on Meta', [
                'phone_number_id' => $phoneNumberId,
                'error' => $error,
            ]);

            return [
                'success' => false,
                'message' => $error['message'] ?? 'Failed to deregister phone number from Meta.',
                'raw' => $response->json(),
            ];
        } catch (\Throwable $e) {
            Log::error('MetaWhatsApp: Exception while deregistering phone number: '.$e->getMessage(), [
                'phone_number_id' => $phoneNumberId,
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch phone numbers under a WABA.
     */
    public function getWabaPhoneNumbers(?string $wabaId = null): array
    {
        if (! $this->isConfigured()) {
            Log::warning('MetaWhatsApp: getWabaPhoneNumbers called but credentials are not configured.');

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

        $error = $response->json()['error'] ?? [];
        Log::error('MetaWhatsApp: Failed to fetch WABA phone numbers', [
            'waba_id' => $waba,
            'status' => $response->status(),
            'error' => $error,
        ]);

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
            Log::warning('MetaWhatsApp: getPhoneNumberDetails called but credentials are not configured.', [
                'phone_number_id' => $phoneNumberId,
            ]);

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

        $error = $response->json()['error'] ?? [];
        Log::error('MetaWhatsApp: Failed to fetch phone number details', [
            'phone_number_id' => $phoneNumberId,
            'status' => $response->status(),
            'error' => $error,
        ]);

        return [
            'success' => false,
            'message' => $error['message'] ?? 'Failed to fetch phone number details from Meta.',
            'raw' => $response->json(),
        ];
    }

    /**
     * Get WhatsApp Business Profile details from Meta (profile_picture_url, about, description, etc.).
     *
     * @return array<string, mixed>|null
     */
    public function getBusinessProfile(string $phoneNumberId, ?string $token = null): ?array
    {
        $accessToken = $token ?: $this->systemUserToken;
        if (empty($accessToken) || empty($phoneNumberId)) {
            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->retry(2, 200, throw: false)
                ->get("{$this->baseUrl}/{$phoneNumberId}/whatsapp_business_profile", [
                    'fields' => 'about,address,description,email,profile_picture_url,websites,vertical',
                ]);

            if ($response->successful()) {
                return $response->json('data.0') ?? null;
            }
        } catch (\Throwable $e) {
            Log::warning('MetaWhatsApp: Failed to fetch business profile', [
                'phone_number_id' => $phoneNumberId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Get the profile picture URL for a WhatsApp business phone number.
     */
    public function getBusinessProfilePicture(string $phoneNumberId, ?string $token = null): ?string
    {
        $profile = $this->getBusinessProfile($phoneNumberId, $token);

        return $profile['profile_picture_url'] ?? null;
    }

    /**
     * Normalize a recipient's phone number into clean digits without leading plus,
     * defaulting to Egypt (+20) if no country code is present.
     */
    public function normalizeRecipientPhone(string $phone, string $defaultCountryCode = '20'): string
    {
        $clean = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($clean, '00')) {
            $clean = substr($clean, 2);
        }

        // Egyptian local mobile format (010..., 011..., 012..., 015... - 11 digits)
        if (preg_match('/^0(1[0125][0-9]{8})$/', $clean, $matches)) {
            return '20'.$matches[1];
        }

        // 10 digits starting with 1 (e.g. 1012345678)
        if (preg_match('/^(1[0125][0-9]{8})$/', $clean, $matches)) {
            return '20'.$matches[1];
        }

        // Already Egyptian format with country code (2010... - 12 digits)
        if (preg_match('/^20(1[0125][0-9]{8})$/', $clean)) {
            return $clean;
        }

        // Saudi Arabia local (05xxxxxxxx)
        if (preg_match('/^0(5[0-9]{8})$/', $clean, $matches)) {
            return '966'.$matches[1];
        }
        if (preg_match('/^966(5[0-9]{8})$/', $clean)) {
            return $clean;
        }

        // UAE local (05xxxxxxxx)
        if (preg_match('/^0(5[0-9]{8})$/', $clean, $matches)) {
            return '971'.$matches[1];
        }
        if (preg_match('/^971([0-9]{8,9})$/', $clean)) {
            return $clean;
        }

        // If number starts with 0 and doesn't match above, strip 0 and prepend default country code (20)
        if (str_starts_with($clean, '0')) {
            return $defaultCountryCode.ltrim($clean, '0');
        }

        // If short national number without CC (e.g. 9 or 10 digits), prepend default country code
        if (strlen($clean) <= 10 && ! str_starts_with($clean, $defaultCountryCode)) {
            return $defaultCountryCode.$clean;
        }

        return $clean;
    }

    /**
     * Ensure the Arabic order confirmation template with Quick Reply buttons exists on the WABA.
     *
     * @return array{success: bool, message: string, template_id?: string}
     */
    public function ensureOrderConfirmationTemplate(WhatsItem $whatsItem): array
    {
        $wabaId = $whatsItem->customer_waba_id ?: ($whatsItem->waba_id ?: $this->wabaId);
        $token = $whatsItem->access_token ?: $this->systemUserToken;

        if (empty($wabaId) || empty($token)) {
            return [
                'success' => false,
                'message' => 'WABA ID or Access Token is missing.',
            ];
        }

        // 1. Check if template already exists
        try {
            $checkResponse = Http::withToken($token)
                ->timeout(15)
                ->get("{$this->baseUrl}/{$wabaId}/message_templates", [
                    'name' => 'order_confirmation',
                ]);

            if ($checkResponse->successful()) {
                $existing = $checkResponse->json('data', []);
                foreach ($existing as $t) {
                    if (($t['name'] ?? '') === 'order_confirmation' && ($t['language'] ?? '') === 'ar') {
                        return [
                            'success' => true,
                            'message' => 'Template already exists.',
                            'template_id' => $t['id'] ?? null,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('MetaWhatsApp: Failed to query existing message templates', [
                'waba_id' => $wabaId,
                'error' => $e->getMessage(),
            ]);
        }

        // 2. Create the order_confirmation template
        $templatePayload = [
            'name' => 'order_confirmation',
            'category' => 'UTILITY',
            'language' => 'ar',
            'components' => [
                [
                    'type' => 'BODY',
                    'text' => "أهلاً {{1}}، طلبك رقم {{2}} من {{3}} بإجمالي {{4}} جنيه.\nبرجاء اختيار تأكيد الطلب لتجهيزه فوراً، أو إلغاء الطلب.",
                    'example' => [
                        'body_text' => [['أحمد', '1045', 'اسم المتجر', '250']],
                    ],
                ],
                [
                    'type' => 'BUTTONS',
                    'buttons' => [
                        ['type' => 'QUICK_REPLY', 'text' => 'تأكيد الطلب'],
                        ['type' => 'QUICK_REPLY', 'text' => 'إلغاء الطلب'],
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->post("{$this->baseUrl}/{$wabaId}/message_templates", $templatePayload);

            if ($response->successful()) {
                Log::info('MetaWhatsApp: Order confirmation template created on WABA', [
                    'waba_id' => $wabaId,
                    'response' => $response->json(),
                ]);

                return [
                    'success' => true,
                    'message' => 'Template created successfully.',
                    'template_id' => $response->json('id'),
                ];
            }

            $error = $response->json('error', []);
            // Code 100 with subcode indicates template already exists
            if (($error['code'] ?? null) === 100 && str_contains((string) ($error['message'] ?? ''), 'already exists')) {
                return [
                    'success' => true,
                    'message' => 'Template already exists on Meta.',
                ];
            }

            Log::error('MetaWhatsApp: Failed to create order confirmation template', [
                'waba_id' => $wabaId,
                'error' => $error,
            ]);

            return [
                'success' => false,
                'message' => $error['message'] ?? 'Failed to create template on Meta.',
                'raw' => $response->json(),
            ];
        } catch (\Throwable $e) {
            Log::error('MetaWhatsApp: Exception creating template: '.$e->getMessage(), [
                'waba_id' => $wabaId,
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send an order confirmation template message with Quick Reply buttons to a customer.
     *
     * @return array{success: bool, message: string, message_id?: string}
     */
    public function sendOrderConfirmationMessage(
        WhatsItem $whatsItem,
        string $toPhone,
        EcommerceOrder $order,
        ?string $storeName = null
    ): array {
        $token = $whatsItem->access_token ?: $this->systemUserToken;
        $phoneNumberId = $whatsItem->phone_number_id ?: $this->phoneNumberId;

        if (empty($token) || empty($phoneNumberId)) {
            return [
                'success' => false,
                'message' => 'Missing WhatsApp credentials (token or phone_number_id).',
            ];
        }

        $normalizedTo = $this->normalizeRecipientPhone($toPhone);
        $resolvedStoreName = $storeName ?: ($whatsItem->user?->restuarant_name ?: ($whatsItem->user?->name ?: 'المتجر'));
        $customerName = ! empty($order->customer_name) ? $order->customer_name : 'عميلنا العزيز';
        $orderNumber = (string) (! empty($order->order_number) ? $order->order_number : $order->external_order_id);
        $total = (string) ($order->total_price ?? '0');

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $normalizedTo,
            'type' => 'template',
            'template' => [
                'name' => 'order_confirmation',
                'language' => [
                    'code' => 'ar',
                ],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $customerName],
                            ['type' => 'text', 'text' => $orderNumber],
                            ['type' => 'text', 'text' => $resolvedStoreName],
                            ['type' => 'text', 'text' => $total],
                        ],
                    ],
                    [
                        'type' => 'button',
                        'sub_type' => 'quick_reply',
                        'index' => '0',
                        'parameters' => [
                            ['type' => 'payload', 'payload' => 'ORDER_APPROVE_'.$order->id],
                        ],
                    ],
                    [
                        'type' => 'button',
                        'sub_type' => 'quick_reply',
                        'index' => '1',
                        'parameters' => [
                            ['type' => 'payload', 'payload' => 'ORDER_REJECT_'.$order->id],
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken($token)
                ->timeout(15)
                ->post("{$this->baseUrl}/{$phoneNumberId}/messages", $payload);

            if ($response->successful()) {
                $wamid = $response->json('messages.0.id');
                $order->update(['meta_message_id' => $wamid]);

                if ($whatsItem->msg_number > 0) {
                    $whatsItem->decrement('msg_number');
                }

                if ($whatsItem->user_id) {
                    MsgSend::create([
                        'user_id' => $whatsItem->user_id,
                        'whats_item_id' => $whatsItem->id,
                        'channel' => 'whatsapp',
                        'status' => 'sent',
                    ]);
                }

                Log::info('MetaWhatsApp: Order confirmation template sent successfully', [
                    'whats_item_id' => $whatsItem->id,
                    'order_id' => $order->id,
                    'to' => $normalizedTo,
                    'wamid' => $wamid,
                ]);

                return [
                    'success' => true,
                    'message' => 'Message sent successfully.',
                    'message_id' => $wamid,
                ];
            }

            $error = $response->json('error', []);

            // Fallback: If customer token failed, try with system user token
            if (! empty($this->systemUserToken) && $token !== $this->systemUserToken) {
                $fallbackResponse = Http::withToken($this->systemUserToken)
                    ->timeout(15)
                    ->post("{$this->baseUrl}/{$phoneNumberId}/messages", $payload);

                if ($fallbackResponse->successful()) {
                    $wamid = $fallbackResponse->json('messages.0.id');
                    $order->update(['meta_message_id' => $wamid]);

                    if ($whatsItem->msg_number > 0) {
                        $whatsItem->decrement('msg_number');
                    }

                    if ($whatsItem->user_id) {
                        MsgSend::create([
                            'user_id' => $whatsItem->user_id,
                            'whats_item_id' => $whatsItem->id,
                            'channel' => 'whatsapp',
                            'status' => 'sent',
                        ]);
                    }

                    Log::info("MetaWhatsApp: Order confirmation template sent via fallback system_user_token for {$phoneNumberId}", [
                        'whats_item_id' => $whatsItem->id,
                        'order_id' => $order->id,
                        'to' => $normalizedTo,
                        'wamid' => $wamid,
                        'meta_response' => $fallbackResponse->json(),
                    ]);

                    return [
                        'success' => true,
                        'message' => 'Message sent successfully via system token.',
                        'message_id' => $wamid,
                    ];
                }
            }

            Log::error('MetaWhatsApp: Failed to send order confirmation template', [
                'whats_item_id' => $whatsItem->id,
                'order_id' => $order->id,
                'to' => $normalizedTo,
                'error' => $error,
            ]);

            // Fallback: If template is PENDING or rejected, attempt sending as an interactive button message
            $interactivePayload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $normalizedTo,
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => [
                        'text' => "أهلاً {$customerName}، طلبك رقم {$orderNumber} من {$resolvedStoreName} بإجمالي {$total} جنيه.\nبرجاء اختيار تأكيد الطلب لتجهيزه فوراً، أو إلغاء الطلب.",
                    ],
                    'action' => [
                        'buttons' => [
                            [
                                'type' => 'reply',
                                'reply' => [
                                    'id' => 'ORDER_APPROVE_'.$order->id,
                                    'title' => 'تأكيد الطلب',
                                ],
                            ],
                            [
                                'type' => 'reply',
                                'reply' => [
                                    'id' => 'ORDER_REJECT_'.$order->id,
                                    'title' => 'إلغاء الطلب',
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            try {
                $interactiveRes = Http::withToken($token)
                    ->timeout(15)
                    ->post("{$this->baseUrl}/{$phoneNumberId}/messages", $interactivePayload);

                if ($interactiveRes->successful()) {
                    $wamid = $interactiveRes->json('messages.0.id');
                    $order->update(['meta_message_id' => $wamid]);

                    if ($whatsItem->msg_number > 0) {
                        $whatsItem->decrement('msg_number');
                    }

                    if ($whatsItem->user_id) {
                        MsgSend::create([
                            'user_id' => $whatsItem->user_id,
                            'whats_item_id' => $whatsItem->id,
                            'channel' => 'whatsapp',
                            'status' => 'sent',
                        ]);
                    }

                    return [
                        'success' => true,
                        'message' => 'Message sent successfully via interactive buttons.',
                        'message_id' => $wamid,
                    ];
                }

                if (! empty($this->systemUserToken) && $token !== $this->systemUserToken) {
                    $fallbackInteractive = Http::withToken($this->systemUserToken)
                        ->timeout(15)
                        ->post("{$this->baseUrl}/{$phoneNumberId}/messages", $interactivePayload);

                    if ($fallbackInteractive->successful()) {
                        $wamid = $fallbackInteractive->json('messages.0.id');
                        $order->update(['meta_message_id' => $wamid]);

                        if ($whatsItem->msg_number > 0) {
                            $whatsItem->decrement('msg_number');
                        }

                        if ($whatsItem->user_id) {
                            MsgSend::create([
                                'user_id' => $whatsItem->user_id,
                                'whats_item_id' => $whatsItem->id,
                                'channel' => 'whatsapp',
                                'status' => 'sent',
                            ]);
                        }

                        return [
                            'success' => true,
                            'message' => 'Message sent successfully via interactive buttons (system token).',
                            'message_id' => $wamid,
                        ];
                    }
                }
            } catch (\Throwable) {
                // Ignore fallback exception, return primary error
            }

            return [
                'success' => false,
                'message' => $error['message'] ?? 'Failed to send WhatsApp template message.',
                'error' => $error,
            ];
        } catch (\Throwable $e) {
            Log::error('MetaWhatsApp: Exception sending template: '.$e->getMessage(), [
                'whats_item_id' => $whatsItem->id,
                'order_id' => $order->id,
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send a direct WhatsApp text message to customer (e.g. status confirmation).
     */
    public function sendDirectTextMessage(WhatsItem $whatsItem, string $toPhone, string $text): bool
    {
        $token = $whatsItem->access_token ?: $this->systemUserToken;
        $phoneNumberId = $whatsItem->phone_number_id ?: $this->phoneNumberId;

        if (empty($token) || empty($phoneNumberId)) {
            return false;
        }

        $normalizedTo = $this->normalizeRecipientPhone($toPhone);

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->post("{$this->baseUrl}/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $normalizedTo,
                    'type' => 'text',
                    'text' => ['body' => $text],
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('MetaWhatsApp: Exception sending direct text: '.$e->getMessage());

            return false;
        }
    }
}

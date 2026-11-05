<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsItem;
use App\Services\EmbeddedSignupService;
use App\Services\MetaWhatsAppService;
use App\trait\FiltersDailyTokens;
use App\trait\image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class WhatsItemController extends Controller
{
    use FiltersDailyTokens;
    use image;

    public function __construct(
        protected MetaWhatsAppService $metaService
    ) {}

    /**
     * List all WhatsApp numbers for a user.
     */
    public function index(Request $request, User $user): JsonResponse
    {
        [$startDate, $endDate] = $this->resolveDateRange($request);

        $items = $user->whatsItems()
            ->withSum(['dailyTokens as total_tokens' => function ($q) use ($startDate, $endDate) {
                $this->filterDailyTokensDate($q, $startDate, $endDate);
            }], 'tokens')
            ->latest()
            ->get();

        $items->each(function ($item) {
            $item->total_tokens = (int) ($item->total_tokens ?? 0);
        });

        return response()->json([
            'status' => true,
            'data' => $items,
        ]);
    }

    /**
     * Store a new WhatsApp number for the user.
     */
    public function store(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string|max:50',
            'verified_name' => 'sometimes|nullable|string|max:255',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|string|max:500',
            'api_link' => 'sometimes|nullable|string|max:500',
            'login_link' => 'sometimes|nullable|string|max:500',
            'user_name' => 'sometimes|nullable|string|max:255',
            'password' => 'sometimes|nullable|string|max:255',
            'client_id' => 'sometimes|nullable|string|max:255',
            'client_secret' => 'sometimes|nullable|string|max:500',
            'secret' => 'sometimes|nullable|string|max:500',
            'products_link' => 'sometimes|nullable|string|max:500',
            'product_link' => 'sometimes|nullable|string|max:500',
            'auto_request_code' => 'sometimes|boolean',
            'code_method' => 'sometimes|in:SMS,VOICE',
            'ai_context' => 'sometimes|nullable|string',
            'ai_file' => 'sometimes|nullable||file|mimes:txt,text,md|extensions:md|max:2048',
            'order_confirmation_enabled' => 'sometimes|boolean',
            'ecommerce_platform' => 'sometimes|nullable|in:woocommerce,shopify',
            'shopify_access_token' => 'sometimes|nullable|string',
        ]);

        if ($request->filled('client_id') && empty($validated['user_name'])) {
            $validated['user_name'] = $request->input('client_id');
        }
        if ($request->filled('client_secret') && empty($validated['password'])) {
            $validated['password'] = $request->input('client_secret');
        } elseif ($request->filled('secret') && empty($validated['password'])) {
            $validated['password'] = $request->input('secret');
        }

        if ($request->has('product_link') && ! $request->has('products_link')) {
            $validated['products_link'] = $request->input('product_link');
        }

        $phoneNumberId = null;
        $metaResponseInfo = null;

        $verifiedName = $validated['verified_name']
            ?? ($user->restuarant_name ?: ($user->name ?: 'Restaurant'));

        if ($this->metaService->isConfigured()) {
            $addResult = $this->metaService->addPhoneNumber(
                phone: $validated['phone'],
                verifiedName: $verifiedName
            );

            if ($addResult['success']) {
                $phoneNumberId = $addResult['phone_number_id'] ?? null;

                $shouldSendCode = $request->boolean('auto_request_code', true);
                if ($phoneNumberId && $shouldSendCode) {
                    $codeMethod = $request->input('code_method', 'SMS');
                    $codeResult = $this->metaService->requestCode($phoneNumberId, $codeMethod);
                    $metaResponseInfo = [
                        'meta_registered' => true,
                        'phone_number_id' => $phoneNumberId,
                        'otp_sent' => $codeResult['success'],
                        'otp_message' => $codeResult['message'] ?? null,
                    ];
                } else {
                    $metaResponseInfo = [
                        'meta_registered' => true,
                        'phone_number_id' => $phoneNumberId,
                        'otp_sent' => false,
                    ];
                }
            } else {
                $metaResponseInfo = [
                    'meta_registered' => false,
                    'error' => $addResult['error_message'] ?? $addResult['message'] ?? 'Meta API error.',
                    'error_details' => $addResult['message'] ?? null,
                    'error_code' => $addResult['error_code'] ?? null,
                    'error_subcode' => $addResult['error_subcode'] ?? null,
                    'error_user_title' => $addResult['error_user_title'] ?? null,
                    'error_user_msg' => $addResult['error_user_msg'] ?? null,
                ];
            }
        }

        $aiFilePath = null;
        if ($request->hasFile('ai_file')) {
            $aiFilePath = $this->upload($request, 'ai_file', 'whats/ai_files');
        } elseif (isset($validated['ai_file']) && is_string($validated['ai_file'])) {
            $aiFilePath = $validated['ai_file'];
        }

        $orderConfirmationEnabled = $request->boolean('order_confirmation_enabled', false);
        if ($orderConfirmationEnabled) {
            WhatsItem::where('user_id', $user->id)->update(['order_confirmation_enabled' => false]);
        }

        $whatsItem = WhatsItem::create([
            'user_id' => $user->id,
            'phone' => $validated['phone'],
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $this->metaService->getWabaId(),
            'access_token' => $this->metaService->getSystemUserToken(),
            'android_link' => $validated['android_link'] ?? null,
            'ios_link' => $validated['ios_link'] ?? null,
            'website_url' => $validated['website_url'] ?? null,
            'api_link' => $validated['api_link'] ?? null,
            'login_link' => $validated['login_link'] ?? null,
            'user_name' => $validated['user_name'] ?? null,
            'password' => $validated['password'] ?? null,
            'products_link' => $validated['products_link'] ?? null,
            'ai_context' => $validated['ai_context'] ?? null,
            'ai_file' => $aiFilePath,
            'phone_status' => 'pending_otp',
            'msg_number' => 0,
            'order_confirmation_enabled' => $orderConfirmationEnabled,
            'ecommerce_platform' => $validated['ecommerce_platform'] ?? 'woocommerce',
            'store_webhook_token' => bin2hex(random_bytes(16)),
            'shopify_access_token' => $validated['shopify_access_token'] ?? null,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'WhatsApp number added successfully.',
            'data' => $whatsItem,
            'meta' => $metaResponseInfo,
        ], Response::HTTP_CREATED);
    }

    /**
     * Display a specific WhatsApp item.
     */
    public function show(User $user, WhatsItem $whatsItem): JsonResponse
    {
        $this->authorizeItem($user, $whatsItem);

        return response()->json([
            'status' => true,
            'data' => $whatsItem->makeVisible('user_data'),
        ]);
    }

    /**
     * Update a WhatsApp item.
     */
    public function update(Request $request, User $user, WhatsItem $whatsItem): JsonResponse
    {
        $this->authorizeItem($user, $whatsItem);

        $validated = $request->validate([
            'phone' => 'sometimes|required|string|max:50',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|string|max:500',
            'api_link' => 'sometimes|nullable|string|max:500',
            'login_link' => 'sometimes|nullable|string|max:500',
            'user_name' => 'sometimes|nullable|string|max:255',
            'password' => 'sometimes|nullable|string|max:255',
            'client_id' => 'sometimes|nullable|string|max:255',
            'client_secret' => 'sometimes|nullable|string|max:500',
            'secret' => 'sometimes|nullable|string|max:500',
            'products_link' => 'sometimes|nullable|string|max:500',
            'product_link' => 'sometimes|nullable|string|max:500',
            'phone_status' => 'sometimes|in:pending_otp,verified,active',
            'ai_context' => 'sometimes|nullable|string',
            'ai_file' => 'sometimes|nullable',
            'user_data' => 'sometimes|nullable',
            'order_confirmation_enabled' => 'sometimes|boolean',
            'ecommerce_platform' => 'sometimes|nullable|in:woocommerce,shopify',
            'shopify_access_token' => 'sometimes|nullable|string',
        ]);

        if ($request->filled('client_id') && empty($validated['user_name'])) {
            $validated['user_name'] = $request->input('client_id');
        }
        if ($request->filled('client_secret') && empty($validated['password'])) {
            $validated['password'] = $request->input('client_secret');
        } elseif ($request->filled('secret') && empty($validated['password'])) {
            $validated['password'] = $request->input('secret');
        }

        if ($request->has('product_link') && ! $request->has('products_link')) {
            $validated['products_link'] = $request->input('product_link');
        }

        if ($request->hasFile('ai_file')) {
            $updatedPath = $this->update_image($request, $whatsItem->ai_file, 'ai_file', 'whats/ai_files');
            if ($updatedPath) {
                $validated['ai_file'] = $updatedPath;
            }
        } elseif ($request->exists('ai_file') && is_string($request->input('ai_file'))) {
            $validated['ai_file'] = $request->input('ai_file');
        } else {
            unset($validated['ai_file']);
        }

        if (array_key_exists('api_link', $validated) && ! empty($validated['api_link']) && ! array_key_exists('user_data', $validated)) {
            if ($whatsItem->api_link !== $validated['api_link'] || empty($whatsItem->user_data)) {
                $payload = WhatsItem::fetchProductsPayloadFromApiLink($validated['api_link']);
                if (is_array($payload)) {
                    $validated['user_data'] = $payload;
                }
            }
        } elseif (array_key_exists('api_link', $validated) && empty($validated['api_link']) && ! array_key_exists('user_data', $validated)) {
            $validated['user_data'] = null;
        }

        if ($request->has('order_confirmation_enabled') && $request->boolean('order_confirmation_enabled')) {
            WhatsItem::where('user_id', $user->id)
                ->where('id', '!=', $whatsItem->id)
                ->update(['order_confirmation_enabled' => false]);
            $validated['order_confirmation_enabled'] = true;

            if (empty($whatsItem->store_webhook_token)) {
                $validated['store_webhook_token'] = bin2hex(random_bytes(16));
            }
        }

        $whatsItem->update($validated);

        $fresh = $whatsItem->fresh()->makeVisible('user_data');
        $freshArray = $fresh->toArray();
        $freshArray['store_webhook_url'] = $fresh->getStoreWebhookUrl();

        return response()->json([
            'status' => true,
            'message' => 'WhatsApp item updated successfully.',
            'data' => $freshArray,
        ]);
    }

    /**
     * Delete a WhatsApp item.
     * Also deregisters the phone number from Meta Cloud API and unsubscribes the WABA webhook if applicable.
     */
    public function destroy(User $user, WhatsItem $whatsItem): JsonResponse
    {
        $this->authorizeItem($user, $whatsItem);

        // 1. Deregister phone number from Meta Cloud API if registered
        if (! empty($whatsItem->phone_number_id)) {
            $token = ($whatsItem->token_type === 'customer' && ! empty($whatsItem->access_token))
                ? $whatsItem->access_token
                : null;

            $this->metaService->deregisterNumber($whatsItem->phone_number_id, $token);
        }

        // 2. If it is an Embedded Signup WABA and no other numbers use this WABA, unsubscribe app webhook
        if (! empty($whatsItem->customer_waba_id)) {
            $hasOtherNumbers = WhatsItem::where('customer_waba_id', $whatsItem->customer_waba_id)
                ->where('id', '!=', $whatsItem->id)
                ->exists();

            if (! $hasOtherNumbers && ! empty($whatsItem->access_token)) {
                app(EmbeddedSignupService::class)->unsubscribeAppFromWaba(
                    $whatsItem->customer_waba_id,
                    $whatsItem->access_token
                );
            }
        }

        // 3. Clear avatar cache & local files
        Cache::forget("whats_item_avatar_{$whatsItem->id}_{$whatsItem->phone_number_id}");

        if ($whatsItem->ai_file) {
            $this->deleteImage($whatsItem->ai_file);
        }

        // 4. Delete database record
        $whatsItem->delete();

        return response()->json([
            'status' => true,
            'message' => 'WhatsApp item deleted and deregistered from Meta successfully.',
        ]);
    }

    /**
     * Request verification OTP code for this WhatsApp item from Meta.
     */
    public function requestCode(Request $request, User $user, WhatsItem $whatsItem): JsonResponse
    {
        $this->authorizeItem($user, $whatsItem);

        $validated = $request->validate([
            'code_method' => 'sometimes|in:SMS,VOICE',
            'language' => 'sometimes|string|max:10',
        ]);

        if (empty($whatsItem->phone_number_id)) {
            $addResult = $this->metaService->addPhoneNumber(
                phone: $whatsItem->phone,
                verifiedName: $user->restuarant_name
            );

            if (! $addResult['success']) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot request code: '.($addResult['message'] ?? 'Failed to add phone to Meta.'),
                ], Response::HTTP_BAD_REQUEST);
            }

            $whatsItem->update([
                'phone_number_id' => $addResult['phone_number_id'],
                'waba_id' => $this->metaService->getWabaId(),
                'access_token' => $whatsItem->access_token ?: $this->metaService->getSystemUserToken(),
            ]);
        }

        $codeMethod = $validated['code_method'] ?? 'SMS';
        $language = $validated['language'] ?? 'ar';

        $result = $this->metaService->requestCode($whatsItem->phone_number_id, $codeMethod, $language);

        if ($result['success']) {
            return response()->json([
                'status' => true,
                'message' => "Verification code sent to {$whatsItem->phone} via {$codeMethod}.",
                'data' => $result['data'] ?? [],
                'whats_item' => $whatsItem->fresh(),
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => $result['message'] ?? 'Failed to send verification code.',
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * Verify OTP code and register phone number on WhatsApp Cloud API.
     */
    public function verifyAndRegister(Request $request, User $user, WhatsItem $whatsItem): JsonResponse
    {
        $this->authorizeItem($user, $whatsItem);

        $validated = $request->validate([
            'code' => 'required|string|max:10',
            'pin' => 'required|string|size:6|regex:/^[0-9]+$/',
        ]);

        if (empty($whatsItem->phone_number_id)) {
            return response()->json([
                'status' => false,
                'message' => 'WhatsApp item does not have a phone_number_id from Meta. Request a code first.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // 1. Verify OTP code
        $verifyResult = $this->metaService->verifyCode($whatsItem->phone_number_id, $validated['code']);

        if (! $verifyResult['success']) {
            return response()->json([
                'status' => false,
                'message' => $verifyResult['message'] ?? 'Invalid verification code.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // 2. Register number on Cloud API using PIN
        $registerResult = $this->metaService->registerNumber($whatsItem->phone_number_id, $validated['pin']);

        if (! $registerResult['success']) {
            $whatsItem->update(['phone_status' => 'verified']);

            return response()->json([
                'status' => false,
                'message' => 'Code verified, but failed to register on Cloud API: '.($registerResult['message'] ?? ''),
                'phone_status' => 'verified',
                'data' => $whatsItem->fresh()->makeVisible('user_data'),
            ], Response::HTTP_BAD_REQUEST);
        }

        // 3. Mark active and verified
        $whatsItem->update([
            'phone_status' => 'active',
            'phone_verified_at' => now(),
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'msg_number' => 1000,
            'access_token' => $whatsItem->access_token ?: $this->metaService->getSystemUserToken(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Phone number verified and registered on WhatsApp Cloud API successfully!',
            'data' => $whatsItem->fresh()->makeVisible('user_data'),
        ]);
    }

    /**
     * Sync phone number status directly from Meta Graph API.
     */
    public function syncMetaStatus(User $user, WhatsItem $whatsItem): JsonResponse
    {
        $this->authorizeItem($user, $whatsItem);

        if (empty($whatsItem->phone_number_id)) {
            return response()->json([
                'status' => false,
                'message' => 'WhatsApp item does not have a phone_number_id from Meta.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $details = $this->metaService->getPhoneNumberDetails($whatsItem->phone_number_id);

        if (! $details['success']) {
            return response()->json([
                'status' => false,
                'message' => $details['message'] ?? 'Failed to retrieve details from Meta.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $metaData = $details['data'] ?? [];
        $codeStatus = $metaData['code_verification_status'] ?? null;
        $status = $metaData['status'] ?? null;

        if ($status === 'CONNECTED' || $codeStatus === 'VERIFIED') {
            $whatsItem->update([
                'phone_status' => 'active',
                'phone_verified_at' => $whatsItem->phone_verified_at ?: now(),
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Meta status synced successfully.',
            'data' => [
                'whats_item_id' => $whatsItem->id,
                'phone_status' => $whatsItem->fresh()->phone_status,
                'meta' => $metaData,
            ],
        ]);
    }

    /**
     * Register an already-verified phone number on WhatsApp Cloud API with a 6-digit PIN.
     * Transitions Meta status from PENDING to CONNECTED, activates the number,
     * and sets start_date = now, end_date = now + 1 month, msg_number = 1000.
     *
     * POST /admin/users/{user}/whats-items/{whatsItem}/register
     *
     * Body:
     *   pin   string   required, 6-digit numeric PIN
     */
    public function registerPhone(Request $request, User $user, WhatsItem $whatsItem): JsonResponse
    {
        $this->authorizeItem($user, $whatsItem);

        $validated = $request->validate([
            'pin' => 'required|string|size:6|regex:/^[0-9]+$/',
        ]);

        if (empty($whatsItem->phone_number_id)) {
            return response()->json([
                'status' => false,
                'message' => 'WhatsApp item does not have a phone_number_id from Meta.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $token = ($whatsItem->token_type === 'customer' && ! empty($whatsItem->access_token))
            ? $whatsItem->access_token
            : $this->metaService->getSystemUserToken();

        $result = $this->metaService->registerNumber(
            $whatsItem->phone_number_id,
            $validated['pin'],
            $token
        );

        if (! $result['success']) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to register on Cloud API: '.($result['message'] ?? ''),
                'raw' => $result['raw'] ?? null,
            ], Response::HTTP_BAD_REQUEST);
        }

        $whatsItem->update([
            'phone_status' => 'active',
            'phone_verified_at' => $whatsItem->phone_verified_at ?: now(),
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'msg_number' => 1000,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Phone number registered and connected to WhatsApp Cloud API successfully!',
            'data' => $whatsItem->fresh()->makeVisible('user_data'),
        ]);
    }

    /**
     * Authorize that the WhatsItem belongs to the User.
     */
    private function authorizeItem(User $user, WhatsItem $whatsItem): void
    {
        abort_if(
            $whatsItem->user_id !== $user->id,
            Response::HTTP_NOT_FOUND,
            'WhatsApp item not found for this user.'
        );
    }
}

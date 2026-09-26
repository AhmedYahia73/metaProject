<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsItem;
use App\Services\MetaWhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WhatsItemController extends Controller
{
    public function __construct(
        protected MetaWhatsAppService $metaService
    ) {}

    /**
     * List all WhatsApp numbers for a user.
     */
    public function index(User $user): JsonResponse
    {
        $items = $user->whatsItems()->latest()->get();

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
            'auto_request_code' => 'sometimes|boolean',
            'code_method' => 'sometimes|in:SMS,VOICE',
        ]);

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

        $whatsItem = WhatsItem::create([
            'user_id' => $user->id,
            'phone' => $validated['phone'],
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $this->metaService->getWabaId(),
            'access_token' => $this->metaService->getSystemUserToken(),
            'android_link' => $validated['android_link'] ?? null,
            'ios_link' => $validated['ios_link'] ?? null,
            'phone_status' => 'pending_otp',
            'msg_number' => 0,
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
            'data' => $whatsItem,
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
            'phone_status' => 'sometimes|in:pending_otp,verified,active',
        ]);

        $whatsItem->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'WhatsApp item updated successfully.',
            'data' => $whatsItem->fresh(),
        ]);
    }

    /**
     * Delete a WhatsApp item.
     */
    public function destroy(User $user, WhatsItem $whatsItem): JsonResponse
    {
        $this->authorizeItem($user, $whatsItem);

        $whatsItem->delete();

        return response()->json([
            'status' => true,
            'message' => 'WhatsApp item deleted successfully.',
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
                'data' => $whatsItem->fresh(),
            ], Response::HTTP_BAD_REQUEST);
        }

        // 3. Mark active and verified
        $whatsItem->update([
            'phone_status' => 'active',
            'phone_verified_at' => now(),
            'access_token' => $whatsItem->access_token ?: $this->metaService->getSystemUserToken(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Phone number verified and registered on WhatsApp Cloud API successfully!',
            'data' => $whatsItem->fresh(),
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

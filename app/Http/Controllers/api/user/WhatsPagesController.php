<?php

namespace App\Http\Controllers\api\user;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Package;
use App\Models\WhatsItem;
use App\Services\MetaWhatsAppService;
use App\trait\image;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class WhatsPagesController extends Controller
{
    use image;

    public function __construct(
        protected MetaWhatsAppService $metaService
    ) {}

    /**
     * Get available packages for WhatsApp subscription.
     */
    public function whats_packages(Request $request): JsonResponse
    {
        $request->validate([
            'lang' => 'sometimes|in:ar,en',
        ]);
        $rawLang = $request->query('lang', $request->header('Accept-Language', 'ar'));
        $lang = str_starts_with(strtolower((string) $rawLang), 'en') ? 'en' : 'ar';

        $whats_packages = Package::with(['discount', 'tax'])
            ->where(function ($query) {
                $query->where('type', 'all')
                    ->orWhere('type', 'whats');
            })
            ->latest()
            ->get()
            ->map(function (Package $package) use ($lang) {
                $names = is_array($package->name)
                    ? $package->name
                    : (json_decode((string) $package->name, true) ?: []);

                $localizedName = $names[$lang] ?? $names['en'] ?? $names['ar'] ?? (is_string($package->name) ? $package->name : '');

                return [
                    'id' => $package->id,
                    'name' => $localizedName,
                    'names' => $names,
                    'msg_number' => $package->msg_number,
                    'price' => $package->price,
                    'months' => $package->months,
                    'discount_id' => $package->discount_id,
                    'tax_id' => $package->tax_id,
                    'discount' => $package->discount,
                    'tax' => $package->tax,
                    'created_at' => $package->created_at,
                    'updated_at' => $package->updated_at,
                ];
            });

        return response()->json([
            'status' => true,
            'lang' => $lang,
            'whats_packages' => $whats_packages,
        ]);
    }

    /**
     * List all WhatsApp numbers (WhatsItems) for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = $user->whatsItems()->latest()->get();

        return response()->json([
            'status' => true,
            'data' => $items,
        ]);
    }

    /**
     * Alias for index to match route 'whats/pages'.
     */
    public function pages(Request $request): JsonResponse
    {
        return $this->index($request);
    }

    /**
     * Add a new WhatsApp phone number for the authenticated restaurant.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'phone' => 'required|string|max:50',
            'verified_name' => 'sometimes|nullable|string|max:255',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|string|max:500',
            'auto_request_code' => 'sometimes|boolean',
            'code_method' => 'sometimes|in:SMS,VOICE',
            'ai_context' => 'sometimes|nullable|string',
            'ai_file' => 'sometimes|nullable',
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

        $aiFilePath = null;
        if ($request->hasFile('ai_file')) {
            $aiFilePath = $this->upload($request, 'ai_file', 'whats/ai_files');
        } elseif (isset($validated['ai_file']) && is_string($validated['ai_file'])) {
            $aiFilePath = $validated['ai_file'];
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
            'ai_context' => $validated['ai_context'] ?? null,
            'ai_file' => $aiFilePath,
            'phone_status' => 'pending_otp',
            'msg_number' => 0,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'WhatsApp number added successfully. Verification OTP has been initiated.',
            'data' => $whatsItem,
            'meta' => $metaResponseInfo,
        ], Response::HTTP_CREATED);
    }

    /**
     * Show a single WhatsApp item for the authenticated user.
     */
    public function show(Request $request, WhatsItem $whatsItem): JsonResponse
    {
        $this->authorizeItem($request->user(), $whatsItem);

        return response()->json([
            'status' => true,
            'data' => $whatsItem,
        ]);
    }

    /**
     * Update an existing WhatsApp item.
     */
    public function update(Request $request, WhatsItem $whatsItem): JsonResponse
    {
        $this->authorizeItem($request->user(), $whatsItem);

        $validated = $request->validate([
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|string|max:500',
            'ai_context' => 'sometimes|nullable|string',
            'ai_file' => 'sometimes|nullable',
        ]);

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
    public function destroy(Request $request, WhatsItem $whatsItem): JsonResponse
    {
        $this->authorizeItem($request->user(), $whatsItem);

        if ($whatsItem->ai_file) {
            $this->deleteImage($whatsItem->ai_file);
        }

        $whatsItem->delete();

        return response()->json([
            'status' => true,
            'message' => 'WhatsApp item deleted successfully.',
        ]);
    }

    /**
     * Request verification OTP code for this WhatsApp item.
     */
    public function requestCode(Request $request, WhatsItem $whatsItem): JsonResponse
    {
        $user = $request->user();
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
    public function verifyAndRegister(Request $request, WhatsItem $whatsItem): JsonResponse
    {
        $user = $request->user();
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
    public function syncMetaStatus(Request $request, WhatsItem $whatsItem): JsonResponse
    {
        $this->authorizeItem($request->user(), $whatsItem);

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
     * Request a WhatsApp subscription order for a WhatsItem.
     */
    public function requestSubscription(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'whats_item_id' => 'required|exists:whats_items,id',
            'package_id' => [
                'required',
                Rule::exists('packages', 'id')->where(function ($query) {
                    $query->whereIn('type', ['whats', 'all']);
                }),
            ],
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|string|max:500',
            'ai_context' => 'sometimes|nullable|string',
            'ai_file' => 'sometimes|nullable',
        ], [
            'package_id.exists' => 'The selected package is invalid or not available for WhatsApp.',
        ]);

        $whatsItem = WhatsItem::where('user_id', $user->id)->findOrFail($validated['whats_item_id']);

        // Check if there is already a pending order for this WhatsApp item
        $pendingOrder = Order::where('whats_item_id', $whatsItem->id)
            ->where('status', 'pending')
            ->exists();

        if ($pendingOrder) {
            return response()->json([
                'status' => false,
                'message' => 'This WhatsApp number already has a pending subscription request awaiting approval.',
            ], Response::HTTP_CONFLICT);
        }

        // Update optional links and AI config on the WhatsApp item
        $itemUpdate = [];

        if ($request->has('android_link')) {
            $itemUpdate['android_link'] = $validated['android_link'] ?? null;
        }

        if ($request->has('ios_link')) {
            $itemUpdate['ios_link'] = $validated['ios_link'] ?? null;
        }

        if ($request->has('website_url')) {
            $itemUpdate['website_url'] = $validated['website_url'] ?? null;
        }

        if ($request->has('ai_context')) {
            $itemUpdate['ai_context'] = $validated['ai_context'] ?? null;
        }

        if ($request->hasFile('ai_file')) {
            $updatedPath = $this->update_image($request, $whatsItem->ai_file, 'ai_file', 'whats/ai_files');
            if ($updatedPath) {
                $itemUpdate['ai_file'] = $updatedPath;
            }
        } elseif ($request->exists('ai_file') && is_string($request->input('ai_file'))) {
            if ($whatsItem->ai_file && $whatsItem->ai_file !== $request->input('ai_file')) {
                $this->deleteImage($whatsItem->ai_file);
            }
            $itemUpdate['ai_file'] = $request->input('ai_file');
        }

        if (! empty($itemUpdate)) {
            $whatsItem->update($itemUpdate);
        }

        $package = Package::with(['discount', 'tax'])->findOrFail($validated['package_id']);

        $basePrice = (float) $package->price;
        $today = Carbon::today();

        $totalDiscount = 0.0;
        $discount = $package->discount;

        if ($discount) {
            $isWithinPeriod = true;

            if ($discount->from && $today->lt(Carbon::parse($discount->from)->startOfDay())) {
                $isWithinPeriod = false;
            }

            if ($discount->to && $today->gt(Carbon::parse($discount->to)->endOfDay())) {
                $isWithinPeriod = false;
            }

            if ($isWithinPeriod) {
                $totalDiscount = $discount->type === 'percentage'
                    ? ($basePrice * (float) $discount->amount) / 100
                    : (float) $discount->amount;

                $totalDiscount = min($totalDiscount, $basePrice);
            }
        }

        $priceAfterDiscount = max(0.0, $basePrice - $totalDiscount);
        $totalTax = 0.0;
        $tax = $package->tax;

        if ($tax) {
            $totalTax = $tax->type === 'percentage'
                ? ($priceAfterDiscount * (float) $tax->amount) / 100
                : (float) $tax->amount;
        }

        $finalPrice = $basePrice - $totalDiscount + $totalTax;
        $msgs = (int) $package->msg_number;

        $order = Order::create([
            'package_id' => $package->id,
            'user_id' => $user->id,
            'total_discount' => round($totalDiscount, 2),
            'total_tax' => round($totalTax, 2),
            'price' => round($basePrice, 2),
            'final_price' => round($finalPrice, 2),
            'msgs' => $msgs,
            'status' => 'pending',
            'channel' => 'whatsapp',
            'whats_item_id' => $whatsItem->id,
            'from' => null,
            'to' => null,
        ]);

        Log::info('WhatsApp subscription requested', [
            'user_id' => $user->id,
            'whats_item_id' => $whatsItem->id,
            'order_id' => $order->id,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Subscription request submitted. Awaiting admin approval.',
            'data' => [
                'order_id' => $order->id,
                'whats_item_id' => $whatsItem->id,
                'phone' => $whatsItem->phone,
                'package' => [
                    'id' => $package->id,
                    'name' => $package->name,
                    'msg_number' => $msgs,
                    'months' => $package->months,
                ],
                'price' => round($basePrice, 2),
                'total_discount' => round($totalDiscount, 2),
                'total_tax' => round($totalTax, 2),
                'final_price' => round($finalPrice, 2),
                'status' => 'pending',
                'whats_item' => $whatsItem->fresh(),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Authorize that the WhatsItem belongs to the authenticated user.
     */
    private function authorizeItem(mixed $user, WhatsItem $whatsItem): void
    {
        abort_if(
            $whatsItem->user_id !== $user->id,
            Response::HTTP_NOT_FOUND,
            'WhatsApp item not found for this user.'
        );
    }
}

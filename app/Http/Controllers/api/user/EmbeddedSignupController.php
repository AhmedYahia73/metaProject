<?php

namespace App\Http\Controllers\api\user;

use App\Http\Controllers\Controller;
use App\Models\WhatsItem;
use App\Services\EmbeddedSignupService;
use App\trait\image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EmbeddedSignupController extends Controller
{
    use image;

    public function __construct(
        protected EmbeddedSignupService $signupService
    ) {}

    /**
     * Step 1 — Complete Embedded Signup.
     *
     * The frontend (Facebook JS SDK) finishes the signup flow and hands us
     * a short-lived `code`. We exchange it for an access token, pull the
     * customer's WABA & phone details, subscribe our app webhook to their WABA,
     * then persist everything in a WhatsItem record.
     *
     * POST /user/whats/embedded-signup
     *
     * Body:
     *   code              string   required – short-lived code from FB SDK
     *   phone_number_id   string   optional – preferred phone number ID (if multiple)
     *   android_link      string   optional
     *   ios_link          string   optional
     *   website_url       string   optional
     *   ai_context        string   optional
     *   ai_file           file     optional
     */
    public function completeSignup(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'code' => 'required|string',
            'phone_number_id' => 'sometimes|nullable|string',
            'pin' => 'sometimes|nullable|string|size:6|regex:/^[0-9]+$/',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|string|max:500',
            'ai_context' => 'sometimes|nullable|string',
            'ai_file' => 'sometimes|nullable|file|mimes:txt,text,md|extensions:md|max:2048',
        ]);

        // ── 1. Exchange code → access token ─────────────────────────────────
        $tokenResult = $this->signupService->exchangeCodeForToken($validated['code']);

        if (! $tokenResult['success']) {
            return response()->json([
                'status' => false,
                'message' => $tokenResult['message'] ?? 'Failed to exchange code for access token.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $customerToken = $tokenResult['access_token'];

        // ── 2. Discover the WABAs & phone numbers granted ────────────────────
        $wabaDetails = $this->signupService->getGrantedWabaDetails($customerToken);

        if (empty($wabaDetails)) {
            return response()->json([
                'status' => false,
                'message' => 'No WhatsApp Business Account was granted during signup. Please try again and approve the WABA access.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Pick the first WABA (customers usually have one)
        $firstWaba = $wabaDetails[0];
        $customerWabaId = $firstWaba['waba_id'];

        // Pick the requested phone_number_id or default to first available
        $preferredId = $validated['phone_number_id'] ?? null;
        $phoneNumberId = $preferredId && in_array($preferredId, $firstWaba['phone_number_ids'], true)
            ? $preferredId
            : ($firstWaba['phone_number_ids'][0] ?? null);

        if (! $phoneNumberId) {
            return response()->json([
                'status' => false,
                'message' => 'No phone number found in the granted WABA. Please add a phone number to your WhatsApp Business Account first.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // ── 3. Fetch phone details ───────────────────────────────────────────
        $phoneDetails = $this->signupService->getPhoneNumberDetails($phoneNumberId, $customerToken);
        $displayPhone = $phoneDetails['display_phone_number'] ?? null;
        $verifiedName = $phoneDetails['verified_name'] ?? ($user->restuarant_name ?: $user->name);
        $phoneStatus = $phoneDetails['status'] ?? 'pending';

        // ── 4. Check if this phone number is already registered ──────────────
        $cleanPhone = preg_replace('/[^0-9]/', '', (string) $displayPhone);
        $existingItem = null;

        if ($cleanPhone) {
            $existingItem = WhatsItem::where('user_id', $user->id)
                ->where(function ($q) use ($cleanPhone, $phoneNumberId) {
                    $q->where('phone_number_id', $phoneNumberId)
                        ->orWhere('phone', $cleanPhone)
                        ->orWhere('phone', '+'.$cleanPhone);
                })
                ->first();
        }

        if ($existingItem) {
            // Update the token and waba info if the phone already exists
            $existingItem->update([
                'access_token' => $customerToken,
                'token_type' => 'customer',
                'waba_id' => $customerWabaId,
                'customer_waba_id' => $customerWabaId,
                'phone_number_id' => $phoneNumberId,
                'phone_status' => $phoneStatus === 'CONNECTED' ? 'active' : $existingItem->phone_status,
                'phone_verified_at' => $phoneStatus === 'CONNECTED' ? ($existingItem->phone_verified_at ?? now()) : $existingItem->phone_verified_at,
            ]);

            // Re-subscribe the app to the WABA webhook
            $this->signupService->subscribeAppToWaba($customerWabaId, $customerToken);

            Log::info('EmbeddedSignup: Updated existing WhatsItem via Embedded Signup', [
                'user_id' => $user->id,
                'whats_item_id' => $existingItem->id,
                'waba_id' => $customerWabaId,
                'phone_number_id' => $phoneNumberId,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'WhatsApp number reconnected and webhook subscribed successfully.',
                'data' => $existingItem->fresh(),
            ]);
        }

        // ── 5. Subscribe our app webhook to the customer's WABA ─────────────
        $subscribeResult = $this->signupService->subscribeAppToWaba($customerWabaId, $customerToken);

        if (! $subscribeResult['success']) {
            Log::warning('EmbeddedSignup: Webhook subscription failed, continuing anyway', [
                'waba_id' => $customerWabaId,
                'error' => $subscribeResult['message'] ?? 'unknown',
            ]);
        }

        // ── 6. Optionally register the phone on Cloud API ───────────────────
        //   Only needed if status != CONNECTED; ignore failure (may be already registered)
        if ($phoneStatus !== 'CONNECTED') {
            $regPin = $validated['pin'] ?? '000000';
            $regResult = $this->signupService->registerPhoneNumber($phoneNumberId, $customerToken, $regPin);
            if (! empty($regResult['success'])) {
                $phoneStatus = 'CONNECTED';
            }
        }

        // ── 7. Handle optional AI file upload ───────────────────────────────
        $aiFilePath = null;
        if ($request->hasFile('ai_file')) {
            $aiFilePath = $this->upload($request, 'ai_file', 'whats/ai_files');
        }

        // ── 8. Persist the new WhatsItem ─────────────────────────────────────
        $isActive = $phoneStatus === 'CONNECTED';
        $whatsItem = WhatsItem::create([
            'user_id' => $user->id,
            'phone' => $displayPhone ?? $cleanPhone,
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $customerWabaId,
            'customer_waba_id' => $customerWabaId,
            'access_token' => $customerToken,
            'token_type' => 'customer',
            'android_link' => $validated['android_link'] ?? null,
            'ios_link' => $validated['ios_link'] ?? null,
            'website_url' => $validated['website_url'] ?? null,
            'ai_context' => $validated['ai_context'] ?? null,
            'ai_file' => $aiFilePath,
            'phone_status' => $isActive ? 'active' : 'pending_otp',
            'phone_verified_at' => $isActive ? now() : null,
            'start_date' => $isActive ? now()->toDateString() : null,
            'end_date' => $isActive ? now()->addMonth()->toDateString() : null,
            'msg_number' => $isActive ? 1000 : 0,
        ]);

        Log::info('EmbeddedSignup: New WhatsItem created', [
            'user_id' => $user->id,
            'whats_item_id' => $whatsItem->id,
            'waba_id' => $customerWabaId,
            'phone_number_id' => $phoneNumberId,
            'phone' => $displayPhone,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'WhatsApp Business Account connected successfully. Auto-reply webhook is now active.',
            'data' => $whatsItem,
            'meta' => [
                'waba_id' => $customerWabaId,
                'phone_number_id' => $phoneNumberId,
                'webhook_subscribed' => $subscribeResult['success'],
                'all_wabas' => $wabaDetails,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * List all WABAs and phone numbers available for a given customer token.
     * Useful for the frontend to let the user choose which number to connect.
     *
     * POST /user/whats/embedded-signup/inspect
     *
     * Body:
     *   code  string  required
     */
    public function inspectCode(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string']);

        $tokenResult = $this->signupService->exchangeCodeForToken($request->input('code'));

        if (! $tokenResult['success']) {
            return response()->json([
                'status' => false,
                'message' => $tokenResult['message'] ?? 'Invalid code.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $customerToken = $tokenResult['access_token'];
        $wabaDetails = $this->signupService->getGrantedWabaDetails($customerToken);

        $enriched = [];
        foreach ($wabaDetails as $waba) {
            $phones = [];
            foreach ($waba['phone_number_ids'] as $pid) {
                $details = $this->signupService->getPhoneNumberDetails($pid, $customerToken);
                if ($details) {
                    $phones[] = $details;
                }
            }
            $enriched[] = [
                'waba_id' => $waba['waba_id'],
                'phones' => $phones,
            ];
        }

        return response()->json([
            'status' => true,
            'access_token' => $customerToken,
            'wabas' => $enriched,
        ]);
    }

    /**
     * Re-subscribe our app webhook to an existing WhatsItem's WABA.
     * Useful if the webhook subscription was lost.
     *
     * POST /user/whats/items/{whatsItem}/resubscribe-webhook
     */
    public function resubscribeWebhook(Request $request, WhatsItem $whatsItem): JsonResponse
    {
        abort_if(
            $whatsItem->user_id !== $request->user()->id,
            Response::HTTP_NOT_FOUND,
            'WhatsApp item not found for this user.'
        );

        if ($whatsItem->token_type !== 'customer' || empty($whatsItem->customer_waba_id)) {
            return response()->json([
                'status' => false,
                'message' => 'This number was not connected via Embedded Signup. Only Embedded Signup numbers support webhook re-subscription.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->signupService->subscribeAppToWaba(
            $whatsItem->customer_waba_id,
            $whatsItem->access_token,
        );

        if ($result['success']) {
            return response()->json([
                'status' => true,
                'message' => 'Webhook re-subscribed successfully. Your auto-reply is now active.',
            ]);
        }

        return response()->json([
            'status' => false,
            'message' => $result['message'] ?? 'Failed to re-subscribe webhook.',
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * Register a verified phone number on WhatsApp Cloud API using a 6-digit PIN.
     * Transitions Meta status from PENDING to CONNECTED, activates the number,
     * and sets start_date = now, end_date = now + 1 month, msg_number = 1000.
     *
     * POST /user/whats/items/{whatsItem}/register
     *
     * Body:
     *   pin   string   required, 6-digit numeric PIN
     */
    public function registerPhone(Request $request, WhatsItem $whatsItem): JsonResponse
    {
        abort_if(
            $whatsItem->user_id !== $request->user()->id,
            Response::HTTP_NOT_FOUND,
            'WhatsApp item not found for this user.'
        );

        $validated = $request->validate([
            'pin' => 'required|string|size:6|regex:/^[0-9]+$/',
        ]);

        if (empty($whatsItem->phone_number_id)) {
            return response()->json([
                'status' => false,
                'message' => 'WhatsApp item does not have a phone_number_id from Meta.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $token = $whatsItem->access_token;
        if (empty($token)) {
            return response()->json([
                'status' => false,
                'message' => 'WhatsApp item does not have an access token.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->signupService->registerPhoneNumber(
            $whatsItem->phone_number_id,
            $token,
            $validated['pin']
        );

        if (! $result['success']) {
            return response()->json([
                'status' => false,
                'message' => $result['message'] ?? 'Failed to register phone number on WhatsApp Cloud API.',
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
            'message' => 'Phone number registered and connected to WhatsApp successfully!',
            'data' => $whatsItem->fresh(),
        ]);
    }
}

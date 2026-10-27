<?php

namespace App\Http\Controllers\api\user;

use App\Http\Controllers\Controller;
use App\Models\TikTokAccount;
use App\Services\TikTokService;
use App\trait\image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TikTokAccountController extends Controller
{
    use image;

    public function __construct(
        protected TikTokService $tiktokService
    ) {}

    /**
     * List all TikTok accounts for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $accounts = $user->tiktokAccounts()
            ->latest()
            ->get()
            ->map(function (TikTokAccount $account) {
                $subInfo = $account->getSubscriptionInfo();

                return [
                    'id' => $account->id,
                    'open_id' => $account->open_id,
                    'username' => $account->username,
                    'display_name' => $account->display_name,
                    'avatar_url' => $account->avatar_url,
                    'status' => $account->status,
                    'msg_number' => $account->msg_number,
                    'start_date' => $account->start_date?->toDateString(),
                    'end_date' => $account->end_date?->toDateString(),
                    'ai_context' => $account->ai_context,
                    'ai_file' => $account->ai_file,
                    'android_link' => $account->android_link,
                    'ios_link' => $account->ios_link,
                    'website_url' => $account->website_url,
                    'subscription_status' => $subInfo['subscription_status'],
                    'available_msgs' => $subInfo['available_msgs'],
                    'created_at' => $account->created_at?->toDateTimeString(),
                ];
            });

        return response()->json([
            'status' => true,
            'data' => $accounts,
        ]);
    }

    /**
     * Generate TikTok OAuth 2.0 authorization URL for connecting a business account.
     */
    public function getAuthUrl(Request $request): JsonResponse
    {
        $user = $request->user();
        $state = Str::random(40)."_{$user->id}";

        $url = $this->tiktokService->getAuthorizationUrl($state, $request->redirect_uri);

        return response()->json([
            'status' => true,
            'data' => [
                'authorization_url' => $url,
                'state' => $state,
            ],
        ]);
    }

    /**
     * Connect a TikTok account either via OAuth code or direct access token.
     */
    public function connect(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'code' => 'sometimes|nullable|string',
            'access_token' => 'sometimes|nullable|string',
            'refresh_token' => 'sometimes|nullable|string',
            'open_id' => 'sometimes|nullable|string',
            'redirect_uri' => 'sometimes|nullable|url',
            'ai_context' => 'sometimes|nullable|string',
            'ai_file' => 'sometimes|nullable|file|mimes:txt,text,md|extensions:md|max:2048',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|string|max:500',
        ]);

        $accessToken = $validated['access_token'] ?? null;
        $refreshToken = $validated['refresh_token'] ?? null;
        $openId = $validated['open_id'] ?? null;
        $tokenExpiresAt = now()->addHours(24);
        $refreshExpiresAt = now()->addDays(365);

        // If authorization code provided, exchange it for tokens
        if (! empty($validated['code'])) {
            $tokenResult = $this->tiktokService->requestAccessToken($validated['code'], $validated['redirect_uri'] ?? null);
            if (! $tokenResult['success']) {
                return response()->json([
                    'status' => false,
                    'message' => $tokenResult['message'] ?? 'Failed to exchange authorization code with TikTok.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $tokenData = $tokenResult['data'];
            $accessToken = $tokenData['access_token'];
            $refreshToken = $tokenData['refresh_token'] ?? null;
            $openId = $tokenData['open_id'] ?? null;
            $tokenExpiresAt = now()->addSeconds((int) ($tokenData['expires_in'] ?? 86400));
            $refreshExpiresAt = now()->addSeconds((int) ($tokenData['refresh_expires_in'] ?? 31536000));
        }

        if (empty($accessToken) || empty($openId)) {
            return response()->json([
                'status' => false,
                'message' => 'Both access_token and open_id (or a valid authorization code) are required.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Fetch User / Business Profile
        $userInfo = $this->tiktokService->getUserInfo($accessToken);
        $userData = $userInfo['data'] ?? [];

        // Check if account is already linked to another user
        $existing = TikTokAccount::where('open_id', $openId)->first();
        if ($existing && $existing->user_id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'This TikTok account is already linked to another user.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $verifyToken = (string) Str::uuid();

        $accountData = [
            'user_id' => $user->id,
            'open_id' => $openId,
            'username' => $userData['username'] ?? ($userData['display_name'] ?? $openId),
            'display_name' => $userData['display_name'] ?? null,
            'avatar_url' => $userData['avatar_url'] ?? null,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_expires_at' => $tokenExpiresAt,
            'refresh_token_expires_at' => $refreshExpiresAt,
            'verify_token' => $verifyToken,
            'status' => 'active',
            'ai_context' => $validated['ai_context'] ?? null,
            'android_link' => $validated['android_link'] ?? null,
            'ios_link' => $validated['ios_link'] ?? null,
            'website_url' => $validated['website_url'] ?? null,
        ];

        if ($request->hasFile('ai_file')) {
            $uploadedPath = $this->upload($request, 'ai_file', 'tiktok/ai_files');
            if ($uploadedPath) {
                $accountData['ai_file'] = $uploadedPath;
            }
        } elseif (! empty($validated['ai_file']) && is_string($validated['ai_file'])) {
            $accountData['ai_file'] = $validated['ai_file'];
        }

        // Give new account trial quota (50 msgs, 30 days) like Telegram
        if (! $existing) {
            $accountData['msg_number'] = 500;
            $accountData['start_date'] = now()->toDateString();
            $accountData['end_date'] = now()->addDays(30)->toDateString();
        }

        /** @var TikTokAccount $account */
        $account = TikTokAccount::updateOrCreate(
            ['open_id' => $openId],
            $accountData
        );

        $subInfo = $account->getSubscriptionInfo();

        return response()->json([
            'status' => true,
            'message' => 'TikTok account connected successfully.',
            'data' => [
                'id' => $account->id,
                'open_id' => $account->open_id,
                'username' => $account->username,
                'display_name' => $account->display_name,
                'avatar_url' => $account->avatar_url,
                'status' => $account->status,
                'msg_number' => $account->msg_number,
                'start_date' => $account->start_date?->toDateString(),
                'end_date' => $account->end_date?->toDateString(),
                'ai_context' => $account->ai_context,
                'ai_file' => $account->ai_file,
                'android_link' => $account->android_link,
                'ios_link' => $account->ios_link,
                'website_url' => $account->website_url,
                'subscription_status' => $subInfo['subscription_status'],
                'available_msgs' => $subInfo['available_msgs'],
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Show details of a single TikTok account.
     */
    public function show(Request $request, TikTokAccount $tiktokAccount): JsonResponse
    {
        $this->authorizeAccount($request->user()->id, $tiktokAccount);

        $subInfo = $tiktokAccount->getSubscriptionInfo();

        return response()->json([
            'status' => true,
            'data' => [
                'id' => $tiktokAccount->id,
                'open_id' => $tiktokAccount->open_id,
                'username' => $tiktokAccount->username,
                'display_name' => $tiktokAccount->display_name,
                'avatar_url' => $tiktokAccount->avatar_url,
                'status' => $tiktokAccount->status,
                'msg_number' => $tiktokAccount->msg_number,
                'start_date' => $tiktokAccount->start_date?->toDateString(),
                'end_date' => $tiktokAccount->end_date?->toDateString(),
                'ai_context' => $tiktokAccount->ai_context,
                'ai_file' => $tiktokAccount->ai_file,
                'android_link' => $tiktokAccount->android_link,
                'ios_link' => $tiktokAccount->ios_link,
                'website_url' => $tiktokAccount->website_url,
                'subscription_status' => $subInfo['subscription_status'],
                'available_msgs' => $subInfo['available_msgs'],
                'created_at' => $tiktokAccount->created_at?->toDateTimeString(),
            ],
        ]);
    }

    /**
     * Update TikTok account data (ai_context, ai_file, android_link, ios_link, website_url, status).
     */
    public function update(Request $request, TikTokAccount $tiktokAccount): JsonResponse
    {
        $this->authorizeAccount($request->user()->id, $tiktokAccount);

        $validated = $request->validate([
            'ai_context' => 'sometimes|nullable|string',
            'ai_file' => 'sometimes|nullable|file|mimes:txt,text,md|extensions:md|max:2048',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|string|max:500',
            'status' => 'sometimes|in:active,disabled',
        ]);

        $updateFields = [];

        if ($request->has('ai_context')) {
            $updateFields['ai_context'] = $validated['ai_context'];
        }

        if ($request->hasFile('ai_file')) {
            $updatedPath = $this->update_image($request, $tiktokAccount->ai_file, 'ai_file', 'tiktok/ai_files');
            if ($updatedPath) {
                $updateFields['ai_file'] = $updatedPath;
            }
        } elseif ($request->exists('ai_file') && is_string($request->input('ai_file'))) {
            $updateFields['ai_file'] = $request->input('ai_file');
        }

        if ($request->has('android_link')) {
            $updateFields['android_link'] = $validated['android_link'];
        }

        if ($request->has('ios_link')) {
            $updateFields['ios_link'] = $validated['ios_link'];
        }

        if ($request->has('website_url')) {
            $updateFields['website_url'] = $validated['website_url'];
        }

        if ($request->has('status')) {
            $updateFields['status'] = $validated['status'];
        }

        if (! empty($updateFields)) {
            $tiktokAccount->update($updateFields);
            $tiktokAccount->refresh();
        }

        $subInfo = $tiktokAccount->getSubscriptionInfo();

        return response()->json([
            'status' => true,
            'message' => 'TikTok account updated successfully.',
            'data' => [
                'id' => $tiktokAccount->id,
                'open_id' => $tiktokAccount->open_id,
                'username' => $tiktokAccount->username,
                'display_name' => $tiktokAccount->display_name,
                'avatar_url' => $tiktokAccount->avatar_url,
                'status' => $tiktokAccount->status,
                'msg_number' => $tiktokAccount->msg_number,
                'start_date' => $tiktokAccount->start_date?->toDateString(),
                'end_date' => $tiktokAccount->end_date?->toDateString(),
                'ai_context' => $tiktokAccount->ai_context,
                'ai_file' => $tiktokAccount->ai_file,
                'android_link' => $tiktokAccount->android_link,
                'ios_link' => $tiktokAccount->ios_link,
                'website_url' => $tiktokAccount->website_url,
                'subscription_status' => $subInfo['subscription_status'],
                'available_msgs' => $subInfo['available_msgs'],
            ],
        ]);
    }

    /**
     * Disconnect / remove a TikTok account.
     */
    public function destroy(Request $request, TikTokAccount $tiktokAccount): JsonResponse
    {
        $this->authorizeAccount($request->user()->id, $tiktokAccount);

        $tiktokAccount->delete();

        return response()->json([
            'status' => true,
            'message' => 'TikTok account disconnected successfully.',
        ]);
    }

    /**
     * Manually refresh TikTok access token.
     */
    public function refreshToken(Request $request, TikTokAccount $tiktokAccount): JsonResponse
    {
        $this->authorizeAccount($request->user()->id, $tiktokAccount);

        $result = $this->tiktokService->refreshAccessToken($tiktokAccount);

        return response()->json([
            'status' => $result['success'],
            'message' => $result['message'] ?? 'Token refreshed successfully.',
        ]);
    }

    /**
     * Authorize that the TikTokAccount belongs to the current user.
     */
    protected function authorizeAccount(int $userId, TikTokAccount $tiktokAccount): void
    {
        if ($tiktokAccount->user_id !== $userId) {
            abort(Response::HTTP_FORBIDDEN, 'You do not own this TikTok account.');
        }
    }
}

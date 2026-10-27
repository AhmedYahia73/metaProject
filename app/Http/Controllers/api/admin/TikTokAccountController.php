<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\TikTokAccount;
use App\Models\User;
use App\Services\TikTokService;
use App\trait\FiltersDailyTokens;
use App\trait\image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TikTokAccountController extends Controller
{
    use FiltersDailyTokens;
    use image;

    public function __construct(
        protected TikTokService $tiktokService
    ) {}

    /**
     * List all TikTok accounts linked to a user (restaurant).
     */
    public function index(Request $request, User $user): JsonResponse
    {
        [$startDate, $endDate] = $this->resolveDateRange($request);

        $accounts = $user->tiktokAccounts()
            ->select(
                'id',
                'user_id',
                'open_id',
                'username',
                'display_name',
                'avatar_url',
                'verify_token',
                'status',
                'msg_number',
                'start_date',
                'end_date',
                'ai_context',
                'ai_file',
                'android_link',
                'ios_link',
                'website_url',
                'created_at'
            )
            ->withSum(['dailyTokens as total_tokens' => function ($q) use ($startDate, $endDate) {
                $this->filterDailyTokensDate($q, $startDate, $endDate);
            }], 'tokens')
            ->latest('id')
            ->get();

        $accounts->each(function ($account) {
            $account->total_tokens = (int) ($account->total_tokens ?? 0);
            $subInfo = $account->getSubscriptionInfo();
            $account->subscription_status = $subInfo['subscription_status'];
            $account->available_msgs = $subInfo['available_msgs'];
        });

        return response()->json([
            'status' => true,
            'data' => $accounts,
        ]);
    }

    /**
     * Link / Store a new TikTok account manually for a user.
     */
    public function store(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'open_id' => 'required|string',
            'access_token' => 'required|string',
            'refresh_token' => 'sometimes|nullable|string',
            'username' => 'sometimes|nullable|string',
            'display_name' => 'sometimes|nullable|string',
            'status' => 'sometimes|in:active,disabled',
            'ai_context' => 'sometimes|nullable|string',
            'ai_file' => 'sometimes|nullable|file|mimes:txt,text,md|extensions:md|max:2048',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|string|max:500',
            'msg_number' => 'sometimes|integer|min:0',
            'start_date' => 'sometimes|nullable|date',
            'end_date' => 'sometimes|nullable|date|after_or_equal:start_date',
        ]);

        $openId = trim($validated['open_id']);

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
            'username' => $validated['username'] ?? $openId,
            'display_name' => $validated['display_name'] ?? null,
            'access_token' => $validated['access_token'],
            'refresh_token' => $validated['refresh_token'] ?? null,
            'token_expires_at' => now()->addDay(),
            'verify_token' => $verifyToken,
            'status' => $validated['status'] ?? 'active',
            'ai_context' => $validated['ai_context'] ?? null,
            'android_link' => $validated['android_link'] ?? null,
            'ios_link' => $validated['ios_link'] ?? null,
            'website_url' => $validated['website_url'] ?? null,
            'msg_number' => $validated['msg_number'] ?? 0,
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
        ];

        if ($request->hasFile('ai_file')) {
            $uploadedPath = $this->upload($request, 'ai_file', 'tiktok/ai_files');
            if ($uploadedPath) {
                $accountData['ai_file'] = $uploadedPath;
            }
        } elseif (! empty($validated['ai_file']) && is_string($validated['ai_file'])) {
            $accountData['ai_file'] = $validated['ai_file'];
        }

        /** @var TikTokAccount $account */
        $account = TikTokAccount::updateOrCreate(
            ['open_id' => $openId],
            $accountData
        );

        return response()->json([
            'status' => true,
            'message' => 'TikTok account linked successfully.',
            'data' => $account,
        ], Response::HTTP_CREATED);
    }

    /**
     * View details of a single TikTok account.
     */
    public function show(User $user, TikTokAccount $tiktokAccount): JsonResponse
    {
        $this->authorizeAccount($user, $tiktokAccount);

        $subInfo = $tiktokAccount->getSubscriptionInfo();

        return response()->json([
            'status' => true,
            'data' => [
                'id' => $tiktokAccount->id,
                'user_id' => $tiktokAccount->user_id,
                'open_id' => $tiktokAccount->open_id,
                'username' => $tiktokAccount->username,
                'display_name' => $tiktokAccount->display_name,
                'avatar_url' => $tiktokAccount->avatar_url,
                'verify_token' => $tiktokAccount->verify_token,
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
     * Update a TikTok account from admin panel.
     */
    public function update(Request $request, User $user, TikTokAccount $tiktokAccount): JsonResponse
    {
        $this->authorizeAccount($user, $tiktokAccount);

        $validated = $request->validate([
            'status' => 'sometimes|in:active,disabled',
            'msg_number' => 'sometimes|integer|min:0',
            'start_date' => 'sometimes|nullable|date',
            'end_date' => 'sometimes|nullable|date|after_or_equal:start_date',
            'ai_context' => 'sometimes|nullable|string',
            'ai_file' => 'sometimes|nullable|file|mimes:txt,text,md|extensions:md|max:2048',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|string|max:500',
            'username' => 'sometimes|nullable|string|max:255',
            'display_name' => 'sometimes|nullable|string|max:255',
            'access_token' => 'sometimes|nullable|string',
            'refresh_token' => 'sometimes|nullable|string',
        ]);

        $updateFields = [];

        foreach (['status', 'msg_number', 'start_date', 'end_date', 'ai_context', 'android_link', 'ios_link', 'website_url', 'username', 'display_name', 'access_token', 'refresh_token'] as $field) {
            if ($request->has($field)) {
                $updateFields[$field] = $validated[$field];
            }
        }

        if ($request->hasFile('ai_file')) {
            $updatedPath = $this->update_image($request, $tiktokAccount->ai_file, 'ai_file', 'tiktok/ai_files');
            if ($updatedPath) {
                $updateFields['ai_file'] = $updatedPath;
            }
        } elseif ($request->exists('ai_file') && is_string($request->input('ai_file'))) {
            $updateFields['ai_file'] = $request->input('ai_file');
        }

        if (! empty($updateFields)) {
            $tiktokAccount->update($updateFields);
            $tiktokAccount->refresh();
        }

        $subInfo = $tiktokAccount->getSubscriptionInfo();

        return response()->json([
            'status' => true,
            'message' => 'TikTok account updated successfully.',
            'data' => array_merge($tiktokAccount->toArray(), [
                'subscription_status' => $subInfo['subscription_status'],
                'available_msgs' => $subInfo['available_msgs'],
            ]),
        ]);
    }

    /**
     * Remove / Delete a TikTok account from admin panel.
     */
    public function destroy(User $user, TikTokAccount $tiktokAccount): JsonResponse
    {
        $this->authorizeAccount($user, $tiktokAccount);

        $tiktokAccount->delete();

        return response()->json([
            'status' => true,
            'message' => 'TikTok account deleted successfully.',
        ]);
    }

    /**
     * Regenerate the webhook verify token.
     */
    public function regenerateVerifyToken(User $user, TikTokAccount $tiktokAccount): JsonResponse
    {
        $this->authorizeAccount($user, $tiktokAccount);

        $newToken = (string) Str::uuid();
        $tiktokAccount->update(['verify_token' => $newToken]);

        return response()->json([
            'status' => true,
            'message' => 'Verify token regenerated successfully.',
            'data' => [
                'verify_token' => $newToken,
            ],
        ]);
    }

    /**
     * Authorize that the account belongs to the specified user.
     */
    protected function authorizeAccount(User $user, TikTokAccount $tiktokAccount): void
    {
        if ($tiktokAccount->user_id !== $user->id) {
            abort(Response::HTTP_FORBIDDEN, 'This TikTok account does not belong to the specified user.');
        }
    }
}

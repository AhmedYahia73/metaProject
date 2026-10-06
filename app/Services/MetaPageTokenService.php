<?php

namespace App\Services;

use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaPageTokenService
{
    protected string $baseUrl;

    public function __construct()
    {
        $version = config('services.meta.graph_version', 'v21.0');
        $this->baseUrl = "https://graph.facebook.com/{$version}";
    }

    /**
     * Sanitize a token by trimming whitespace and stripping accidental duplicate prefixes.
     */
    public static function sanitizeToken(?string $token): ?string
    {
        if (empty($token)) {
            return $token;
        }

        $token = trim($token);

        // Fix accidental duplicate prefix e.g. "EAAV123EAAV123abc..." -> "EAAV123abc..."
        $lastEaaPos = strrpos($token, 'EAA');
        if ($lastEaaPos !== false && $lastEaaPos > 0) {
            $candidate = substr($token, $lastEaaPos);
            if (strlen($candidate) >= 30) {
                $token = $candidate;
            }
        }

        return $token;
    }

    /**
     * Exchange a short-lived Facebook User Access Token (1-2 hours) for a long-lived token (60 days).
     * If app_id or app_secret are not configured, returns the original token.
     */
    public function exchangeForLongLivedToken(string $shortLivedToken): string
    {
        $shortLivedToken = self::sanitizeToken($shortLivedToken) ?: $shortLivedToken;
        $appId = config('services.meta.app_id');
        $appSecret = config('services.meta.app_secret');

        if (empty($appId) || empty($appSecret) || empty($shortLivedToken)) {
            return $shortLivedToken;
        }

        try {
            $response = Http::get("{$this->baseUrl}/oauth/access_token", [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'fb_exchange_token' => $shortLivedToken,
            ]);

            if ($response->successful()) {
                $longLived = (string) $response->json('access_token', '');
                if (! empty($longLived)) {
                    Log::info('MetaPageTokenService: successfully exchanged short-lived token for long-lived token (60 days)');

                    return $longLived;
                }
            } else {
                Log::warning('MetaPageTokenService: exchangeForLongLivedToken failed', [
                    'status' => $response->status(),
                    'error' => $response->json(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('MetaPageTokenService: exception during exchangeForLongLivedToken', [
                'error' => $e->getMessage(),
            ]);
        }

        return $shortLivedToken;
    }

    /**
     * Fetch user's Facebook pages from Graph API and update existing MessengerAccounts
     * and InstagramItems in the database with fresh page_access_token / access_token.
     *
     * @return array{synced: bool, pages_count: int, messenger_updated: int, instagram_updated: int}
     */
    public function syncUserPagesAndTokens(User $user, ?string $userAccessToken = null): array
    {
        $rawToken = $userAccessToken ?: $user->facebook_access_token;
        $token = self::sanitizeToken($rawToken);

        if (! empty($token) && ! empty($user->facebook_access_token) && $token !== $user->facebook_access_token) {
            $user->update(['facebook_access_token' => $token]);
            Log::info("MetaPageTokenService: automatically sanitized malformed facebook_access_token for User #{$user->id}");
        }

        if (empty($token)) {
            return [
                'synced' => false,
                'pages_count' => 0,
                'messenger_updated' => 0,
                'instagram_updated' => 0,
            ];
        }

        try {
            $response = Http::withToken($token)
                ->withOptions([
                    'curl' => [
                        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    ],
                ])
                ->timeout(20)
                ->retry(2, 200, throw: false)
                ->get("{$this->baseUrl}/me/accounts", [
                    'fields' => 'id,name,access_token,category,instagram_business_account{id,username,name}',
                    'access_token' => $token,
                ]);

            if (! $response->successful()) {
                Log::warning('MetaPageTokenService: /me/accounts request failed', [
                    'user_id' => $user->id,
                    'status' => $response->status(),
                    'error' => $response->json(),
                ]);

                return [
                    'synced' => false,
                    'pages_count' => 0,
                    'messenger_updated' => 0,
                    'instagram_updated' => 0,
                ];
            }

            $pages = $response->json('data', []);
            $messengerUpdated = 0;
            $instagramUpdated = 0;

            foreach ($pages as $page) {
                $pageId = (string) ($page['id'] ?? '');
                $pageToken = $page['access_token'] ?? null;
                $pageName = $page['name'] ?? null;

                if (! $pageId || ! $pageToken) {
                    continue;
                }

                // 1. Update existing MessengerAccount for this user & page
                $messengerAccount = MessengerAccount::where('user_id', $user->id)
                    ->where('page_id', $pageId)
                    ->first();

                if ($messengerAccount) {
                    $update = ['page_access_token' => $pageToken];
                    if ($pageName && empty($messengerAccount->page_name)) {
                        $update['page_name'] = $pageName;
                    }
                    $messengerAccount->update($update);
                    $messengerUpdated++;

                    if ($messengerAccount->status === 'active') {
                        $this->subscribeFacebookPage($pageId, $pageToken);
                    }
                }

                // 2. Update existing InstagramItem linked to this page or Instagram account
                $igAccount = $page['instagram_business_account'] ?? null;
                $igId = $igAccount ? (string) ($igAccount['id'] ?? '') : null;

                $instagramQuery = InstagramItem::where('user_id', $user->id)
                    ->where(function ($q) use ($pageId, $igId) {
                        $q->where('page_id', $pageId);
                        if ($igId) {
                            $q->orWhere('instagram_id', $igId);
                        }
                    });

                $instagramItems = $instagramQuery->get();

                foreach ($instagramItems as $igItem) {
                    $igUpdate = ['access_token' => $pageToken];
                    if ($igId && empty($igItem->instagram_id)) {
                        $igUpdate['instagram_id'] = $igId;
                    }
                    if ($pageId && empty($igItem->page_id)) {
                        $igUpdate['page_id'] = $pageId;
                    }
                    $igItem->update($igUpdate);
                    $instagramUpdated++;

                    if ($igItem->status === 'active') {
                        $this->subscribeInstagramAccount($igId ?: $igItem->instagram_id, $pageToken, $pageId);
                    }
                }

                if ($instagramItems->isEmpty()) {
                    $singleItem = InstagramItem::where('user_id', $user->id)->first();
                    if ($singleItem && InstagramItem::where('user_id', $user->id)->count() === 1) {
                        $igUpdate = ['access_token' => $pageToken, 'page_id' => $pageId];
                        if ($igId) {
                            $igUpdate['instagram_id'] = $igId;
                        }
                        $singleItem->update($igUpdate);
                        $instagramUpdated++;

                        if ($singleItem->status === 'active') {
                            $this->subscribeInstagramAccount($igId ?: $singleItem->instagram_id, $pageToken, $pageId);
                        }
                    }
                }
            }

            Log::info('MetaPageTokenService: synced pages and tokens successfully', [
                'user_id' => $user->id,
                'pages_count' => count($pages),
                'messenger_updated' => $messengerUpdated,
                'instagram_updated' => $instagramUpdated,
            ]);

            return [
                'synced' => true,
                'pages_count' => count($pages),
                'messenger_updated' => $messengerUpdated,
                'instagram_updated' => $instagramUpdated,
            ];
        } catch (\Throwable $e) {
            Log::error('MetaPageTokenService: exception during sync', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'synced' => false,
                'pages_count' => 0,
                'messenger_updated' => 0,
                'instagram_updated' => 0,
            ];
        }
    }

    /**
     * Subscribe a Facebook Page to receive webhook events (messages, postbacks, message reads, and feed/comments).
     */
    public function subscribeFacebookPage(string $pageId, string $pageToken): bool
    {
        $pageToken = self::sanitizeToken($pageToken) ?: $pageToken;
        if (empty($pageId) || empty($pageToken)) {
            return false;
        }

        try {
            $response = Http::timeout(15)->post("{$this->baseUrl}/{$pageId}/subscribed_apps", [
                'subscribed_fields' => 'messages,messaging_postbacks,message_reads,feed',
                'access_token' => $pageToken,
            ]);

            $success = $response->successful() && ($response->json('success') === true);

            Log::info("MetaPageTokenService: subscribeFacebookPage result for Page {$pageId}", [
                'status' => $response->status(),
                'success' => $success,
                'response' => $response->json(),
            ]);

            return $success;
        } catch (\Throwable $e) {
            Log::warning("MetaPageTokenService: exception subscribing Facebook page {$pageId}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Subscribe an Instagram Business Account and its connected Page to receive webhook events
     * (messages, postbacks, message reads, and comments).
     */
    public function subscribeInstagramAccount(string $instagramId, string $pageToken, ?string $pageId = null): bool
    {
        $pageToken = self::sanitizeToken($pageToken) ?: $pageToken;
        if (empty($pageToken)) {
            return false;
        }

        $igSuccess = false;

        // 1. Subscribe Instagram Business Account
        if (! empty($instagramId)) {
            try {
                $response = Http::timeout(15)->post("{$this->baseUrl}/{$instagramId}/subscribed_apps", [
                    'subscribed_fields' => 'messages,comments',
                    'access_token' => $pageToken,
                ]);

                $igSuccess = $response->successful();

                Log::info("MetaPageTokenService: subscribeInstagramAccount for IG {$instagramId}", [
                    'status' => $response->status(),
                    'success' => $igSuccess,
                    'response' => $response->json(),
                ]);
            } catch (\Throwable $e) {
                Log::warning("MetaPageTokenService: exception subscribing IG {$instagramId}: ".$e->getMessage());
            }
        }

        // 2. Also ensure the connected Facebook Page is subscribed with Instagram & Feed fields
        if (! empty($pageId)) {
            try {
                $pageResponse = Http::timeout(15)->post("{$this->baseUrl}/{$pageId}/subscribed_apps", [
                    'subscribed_fields' => 'messages,messaging_postbacks,message_reads,feed,comments',
                    'access_token' => $pageToken,
                ]);

                Log::info("MetaPageTokenService: subscribe Page {$pageId} for Instagram events", [
                    'status' => $pageResponse->status(),
                    'response' => $pageResponse->json(),
                ]);
            } catch (\Throwable $e) {
                Log::warning("MetaPageTokenService: exception subscribing Page {$pageId} for Instagram: ".$e->getMessage());
            }
        }

        return $igSuccess;
    }

    /**
     * Subscribe all active Messenger accounts and Instagram items for a given user.
     *
     * @return array{messenger_subscribed: int, instagram_subscribed: int}
     */
    public function subscribeUserActiveAccounts(User $user): array
    {
        $messengerCount = 0;
        $instagramCount = 0;

        foreach ($user->messengerAccounts()->where('status', 'active')->whereNotNull('page_access_token')->get() as $account) {
            if ($this->subscribeFacebookPage($account->page_id, $account->page_access_token)) {
                $messengerCount++;
            }
        }

        foreach ($user->instagramItems()->where('status', 'active')->whereNotNull('access_token')->get() as $item) {
            if ($this->subscribeInstagramAccount($item->instagram_id, $item->access_token, $item->page_id)) {
                $instagramCount++;
            }
        }

        return [
            'messenger_subscribed' => $messengerCount,
            'instagram_subscribed' => $instagramCount,
        ];
    }

    /**
     * Check if a Meta API response represents an expired / invalid token error.
     */
    public function isTokenExpiredError(int $statusCode, array $body): bool
    {
        if (in_array($statusCode, [400, 401, 403], true)) {
            $code = data_get($body, 'error.code');
            $subcode = data_get($body, 'error.error_subcode');
            $message = (string) data_get($body, 'error.message', '');
            $type = (string) data_get($body, 'error.type', '');

            if ($code === 190 || in_array($subcode, [463, 467], true)) {
                return true;
            }

            if ($type === 'OAuthException') {
                return true;
            }

            $lowered = strtolower($message);
            if (str_contains($lowered, 'access token') || str_contains($lowered, 'expired') || str_contains($lowered, 'session has expired')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Attempt to refresh the page_access_token for a specific MessengerAccount using the user's facebook_access_token.
     */
    public function refreshMessengerAccountToken(MessengerAccount $account): ?string
    {
        $user = $account->user;
        if (! $user || empty($user->facebook_access_token)) {
            return null;
        }

        $this->syncUserPagesAndTokens($user);

        return $account->fresh()->page_access_token;
    }

    /**
     * Attempt to refresh the access_token for a specific InstagramItem using the user's facebook_access_token.
     */
    public function refreshInstagramItemToken(InstagramItem $item): ?string
    {
        $user = $item->user;
        if (! $user || empty($user->facebook_access_token)) {
            return null;
        }

        $this->syncUserPagesAndTokens($user);

        return $item->fresh()->access_token;
    }
}

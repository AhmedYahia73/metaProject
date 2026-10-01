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
     * Fetch user's Facebook pages from Graph API and update existing MessengerAccounts
     * and InstagramItems in the database with fresh page_access_token / access_token.
     *
     * @return array{synced: bool, pages_count: int, messenger_updated: int, instagram_updated: int}
     */
    public function syncUserPagesAndTokens(User $user, ?string $userAccessToken = null): array
    {
        $token = $userAccessToken ?: $user->facebook_access_token;

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

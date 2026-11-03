<?php

namespace App\Http\Controllers\api\internal;

use App\Http\Controllers\Controller;
use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\TelegramAccount;
use App\Models\TikTokAccount;
use App\Models\WhatsItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChannelProductsWebhookController extends Controller
{
    /**
     * Map of channel identifiers to their respective Eloquent model classes.
     *
     * @var array<string, class-string>
     */
    protected array $channelModels = [
        'instagram' => InstagramItem::class,
        'messenger' => MessengerAccount::class,
        'telegram' => TelegramAccount::class,
        'tiktok' => TikTokAccount::class,
        'whats' => WhatsItem::class,
    ];

    /**
     * Handle incoming scraped products payload from n8n or scraping service.
     */
    public function handle(Request $request): JsonResponse
    {
        // 1. Verify internal authentication token
        $expectedSecret = (string) config('services.n8n.webhook_secret');
        if ($expectedSecret !== '') {
            $providedToken = $request->header('X-Internal-Token')
                ?? $request->bearerToken()
                ?? (string) $request->input('secret_token', '');

            if (! hash_equals($expectedSecret, (string) $providedToken)) {
                Log::warning('[products:scrape-n8n] Unauthorized webhook callback attempt.', [
                    'ip' => $request->ip(),
                    'provided_token_sample' => substr((string) $providedToken, 0, 8).'...',
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: Invalid internal token',
                ], 401);
            }
        }

        // 2. Validate input fields
        $validated = $request->validate([
            'channel' => 'required|string|in:instagram,messenger,telegram,tiktok,whats',
            'account_id' => 'required|integer',
            'status' => 'required|string|in:success,failed',
            'data' => 'nullable|array',
            'products' => 'nullable|array',
            'error_message' => 'nullable|string',
        ]);

        $channel = strtolower($validated['channel']);
        $accountId = (int) $validated['account_id'];
        $status = strtolower($validated['status']);
        $errorMessage = $validated['error_message'] ?? null;

        /** @var class-string<Model> $modelClass */
        $modelClass = $this->channelModels[$channel];

        $account = $modelClass::find($accountId);

        if (! $account) {
            Log::warning("[products:scrape-n8n] Account not found for callback: channel={$channel}, id={$accountId}");

            return response()->json([
                'status' => false,
                'message' => "Account not found for channel [{$channel}] with ID [{$accountId}].",
            ], 404);
        }

        // 3. Handle failure notification from n8n
        if ($status === 'failed') {
            Log::warning("[products:scrape-n8n] Scraping failed for {$channel} #{$accountId}.", [
                'channel' => $channel,
                'account_id' => $accountId,
                'error_message' => $errorMessage,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Scraping failure logged successfully; existing user_data was preserved.',
                'channel' => $channel,
                'account_id' => $accountId,
            ]);
        }

        // 4. Handle success: update user_data
        $rawPayload = $request->input('data') ?? $request->input('products') ?? [];

        // Normalize payload: ensure it is saved as an array
        $account->update([
            'user_data' => $rawPayload,
        ]);

        $itemsList = $rawPayload['data']['data'] ?? $rawPayload['data'] ?? $rawPayload['products'] ?? (array_is_list($rawPayload) ? $rawPayload : []);
        $count = is_array($itemsList) ? count($itemsList) : 0;

        Log::info("[products:scrape-n8n] SUCCESS: Updated user_data for {$channel} #{$accountId}.", [
            'channel' => $channel,
            'account_id' => $accountId,
            'products_count' => $count,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Products data saved to user_data successfully.',
            'channel' => $channel,
            'account_id' => $accountId,
            'products_count' => $count,
        ]);
    }
}

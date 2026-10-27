<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\OpenAiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function __construct(
        protected OpenAiTokenService $openAiTokenService
    ) {}

    /**
     * Show setting where name = 'ai_context'.
     */
    public function getAiContext(): JsonResponse
    {
        $setting = Setting::firstWhere('name', 'ai_context');

        return response()->json([
            'status' => true,
            'data' => [
                'name' => 'ai_context',
                'value' => $setting?->value,
            ],
        ]);
    }

    /**
     * Update if exists, or create if not, setting where name = 'ai_context'.
     */
    public function setAiContext(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'value' => 'required|string',
        ]);

        $setting = Setting::updateOrCreate(
            ['name' => 'ai_context'],
            ['value' => $validated['value'] ?? null]
        );

        return response()->json([
            'status' => true,
            'message' => 'AI context setting saved successfully.',
            'data' => $setting,
        ]);
    }

    /**
     * Get AI token stats (total, used, and remaining).
     */
    public function getAiTokens(): JsonResponse
    {
        $stats = $this->openAiTokenService->getTokenStats();

        return response()->json([
            'status' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Set or update AI token budget.
     */
    public function setAiTokens(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'total_tokens' => 'sometimes|integer|min:0',
            'remaining_tokens' => 'sometimes|integer|min:0',
            'add_tokens' => 'sometimes|integer|min:1',
        ]);

        if ($request->filled('total_tokens')) {
            $this->openAiTokenService->setTotalTokens((int) $validated['total_tokens']);
        } elseif ($request->filled('remaining_tokens')) {
            $this->openAiTokenService->setRemainingTokens((int) $validated['remaining_tokens']);
        } elseif ($request->filled('add_tokens')) {
            $this->openAiTokenService->addTokens((int) $validated['add_tokens']);
        }

        $stats = $this->openAiTokenService->getTokenStats();

        return response()->json([
            'status' => true,
            'message' => 'AI token budget updated successfully.',
            'data' => $stats,
        ]);
    }
}

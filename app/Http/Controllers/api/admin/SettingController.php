<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
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
}

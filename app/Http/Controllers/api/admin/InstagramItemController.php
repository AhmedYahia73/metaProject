<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\InstagramItem;
use App\Models\User;
use App\trait\image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class InstagramItemController extends Controller
{
    use image;

    /**
     * List all Instagram accounts linked to a user (restaurant).
     */
    public function index(User $user): JsonResponse
    {
        $items = $user->instagramItems()
            ->select(
                'id',
                'user_id',
                'instagram_id',
                'username',
                'name',
                'profile_picture_url',
                'page_id',
                'verify_token',
                'status',
                'ai_context',
                'ai_file',
                'android_link',
                'ios_link',
                'website_url',
                'msg_number',
                'created_at',
                'updated_at'
            )
            ->latest()
            ->get();

        return response()->json([
            'status' => true,
            'data' => $items,
        ]);
    }

    /**
     * Link/create a new Instagram account for a user.
     */
    public function store(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'instagram_id' => 'required|string|max:255|unique:instagram_items,instagram_id',
            'access_token' => 'required|string',
            'username' => 'sometimes|nullable|string|max:255',
            'name' => 'sometimes|nullable|string|max:255',
            'profile_picture_url' => 'sometimes|nullable|string',
            'page_id' => 'sometimes|nullable|string|max:255',
            'status' => 'sometimes|in:active,disabled',
            'ai_context' => 'sometimes|nullable|string',
            'ai_file' => 'sometimes|nullable|file|mimes:txt,text,md|extensions:md|max:2048',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|string|max:500',
        ]);

        unset($validated['msg_number']);
        $validated['user_id'] = $user->id;
        $validated['verify_token'] = (string) Str::uuid();

        if ($request->hasFile('ai_file')) {
            $uploadedPath = $this->upload($request, 'ai_file', 'instagram/ai_files');
            if ($uploadedPath) {
                $validated['ai_file'] = $uploadedPath;
            }
        } elseif (isset($validated['ai_file']) && is_string($validated['ai_file'])) {
            $validated['ai_file'] = $validated['ai_file'];
        }

        $item = InstagramItem::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Instagram account linked successfully.',
            'data' => $item->makeVisible('access_token'),
            'setup' => [
                'webhook_url' => url('/api/instagram-webhook'),
                'verify_token' => $item->verify_token,
                'note' => 'Set up the webhook URL and verify token in Meta Developer Dashboard under Instagram Graph API / Webhooks.',
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Display a specific Instagram account.
     */
    public function show(User $user, InstagramItem $instagramItem): JsonResponse
    {
        if ($instagramItem->user_id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Instagram account does not belong to this user.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'status' => true,
            'data' => $instagramItem->makeVisible('access_token'),
            'subscription' => $instagramItem->getSubscriptionInfo(),
        ]);
    }

    /**
     * Update an Instagram account.
     */
    public function update(Request $request, User $user, InstagramItem $instagramItem): JsonResponse
    {
        if ($instagramItem->user_id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Instagram account does not belong to this user.',
            ], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'access_token' => 'sometimes|string',
            'username' => 'sometimes|nullable|string|max:255',
            'name' => 'sometimes|nullable|string|max:255',
            'profile_picture_url' => 'sometimes|nullable|string',
            'page_id' => 'sometimes|nullable|string|max:255',
            'status' => 'sometimes|in:active,disabled',
            'msg_number' => 'sometimes|integer|min:0',
            'ai_context' => 'sometimes|nullable|string',
            'ai_file' => 'sometimes|nullable|file|mimes:txt,text,md|extensions:md|max:2048',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'website_url' => 'sometimes|nullable|string|max:500',
        ]);

        if ($request->hasFile('ai_file')) {
            $uploadedPath = $this->upload($request, 'ai_file', 'instagram/ai_files');
            if ($uploadedPath) {
                $validated['ai_file'] = $uploadedPath;
            }
        } elseif ($request->has('ai_file') && is_string($request->input('ai_file'))) {
            $validated['ai_file'] = $request->input('ai_file');
        }

        $instagramItem->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Instagram account updated successfully.',
            'data' => $instagramItem->fresh()->makeVisible('access_token'),
        ]);
    }

    /**
     * Delete an Instagram account.
     */
    public function destroy(User $user, InstagramItem $instagramItem): JsonResponse
    {
        if ($instagramItem->user_id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Instagram account does not belong to this user.',
            ], Response::HTTP_NOT_FOUND);
        }

        $instagramItem->delete();

        return response()->json([
            'status' => true,
            'message' => 'Instagram account deleted successfully.',
        ]);
    }

    /**
     * Regenerate the webhook verify token.
     */
    public function regenerateVerifyToken(User $user, InstagramItem $instagramItem): JsonResponse
    {
        if ($instagramItem->user_id !== $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Instagram account does not belong to this user.',
            ], Response::HTTP_NOT_FOUND);
        }

        $instagramItem->update(['verify_token' => (string) Str::uuid()]);

        return response()->json([
            'status' => true,
            'message' => 'Verify token regenerated successfully.',
            'verify_token' => $instagramItem->verify_token,
        ]);
    }
}

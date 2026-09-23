<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\MessengerAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class MessengerAccountController extends Controller
{
    /**
     * List all Messenger pages linked to a user (restaurant).
     */
    public function index(User $user): JsonResponse
    {
        $accounts = $user->messengerAccounts()->get();

        return response()->json([
            'status' => true,
            'data' => $accounts,
        ]);
    }

    /**
     * Link a new Facebook Page to the user (restaurant).
     * The system auto-generates a unique verify_token for Meta Dashboard setup.
     */
    public function store(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'page_id' => 'required|string|max:255|unique:messenger_accounts,page_id',
            'page_access_token' => 'required|string',
            'page_name' => 'sometimes|nullable|string|max:255',
            'status' => 'sometimes|in:active,disabled',
        ]);

        $validated['user_id'] = $user->id;
        $validated['verify_token'] = (string) Str::uuid();

        $account = MessengerAccount::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Messenger page linked successfully.',
            'data' => $account->makeVisible('page_access_token'),
            'setup' => [
                'webhook_url' => url('/api/messenger-webhook'),
                'verify_token' => $account->verify_token,
                'note' => 'Copy the verify_token above and paste it in your Meta App Dashboard under Messenger → Webhooks.',
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Show a single Messenger account.
     */
    public function show(User $user, MessengerAccount $messengerAccount): JsonResponse
    {
        $this->authorizeAccount($user, $messengerAccount);

        return response()->json([
            'status' => true,
            'data' => $messengerAccount,
        ]);
    }

    /**
     * Update a Messenger account (page token, name, or status).
     */
    public function update(Request $request, User $user, MessengerAccount $messengerAccount): JsonResponse
    {
        $this->authorizeAccount($user, $messengerAccount);

        $validated = $request->validate([
            'page_name' => 'sometimes|nullable|string|max:255',
            'page_access_token' => 'sometimes|string',
            'status' => 'sometimes|in:active,disabled',
        ]);

        $messengerAccount->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Messenger account updated successfully.',
            'data' => $messengerAccount->fresh(),
        ]);
    }

    /**
     * Remove a Messenger page from the user.
     */
    public function destroy(User $user, MessengerAccount $messengerAccount): JsonResponse
    {
        $this->authorizeAccount($user, $messengerAccount);

        $messengerAccount->delete();

        return response()->json([
            'status' => true,
            'message' => 'Messenger account removed successfully.',
        ]);
    }

    /**
     * Regenerate the verify_token for a Messenger account.
     * Use this if you need to re-configure the webhook in Meta Dashboard.
     */
    public function regenerateVerifyToken(User $user, MessengerAccount $messengerAccount): JsonResponse
    {
        $this->authorizeAccount($user, $messengerAccount);

        $newToken = (string) Str::uuid();
        $messengerAccount->update(['verify_token' => $newToken]);

        return response()->json([
            'status' => true,
            'message' => 'Verify token regenerated. Update it in Meta App Dashboard.',
            'verify_token' => $newToken,
            'webhook_url' => url('/api/messenger-webhook'),
        ]);
    }

    /**
     * Ensure the MessengerAccount belongs to the given User.
     */
    private function authorizeAccount(User $user, MessengerAccount $messengerAccount): void
    {
        abort_if(
            $messengerAccount->user_id !== $user->id,
            Response::HTTP_NOT_FOUND,
            'Messenger account not found for this user.'
        );
    }
}

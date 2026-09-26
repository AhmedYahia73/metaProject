<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WhatsItem;
use App\Services\MetaWhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    public function __construct(
        protected MetaWhatsAppService $metaService
    ) {}

    /**
     * Display a listing of users (paginated).
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Number of users per page (default: 15). Example: 15
     * @queryParam role string Filter users by role (admin, user). Example: user
     * @queryParam phone_status string Filter by phone status. Example: active
     * @queryParam search string Search in restaurant name, phone, name, or email. Example: Ahmed
     * @queryParam paginate boolean Whether to paginate the results (default: true). Example: true
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'phone_status' => 'sometimes|string|max:50',
            'search' => 'sometimes|string|max:255',
            'paginate' => 'sometimes|boolean',
        ]);

        $query = User::with('whatsItems')->where('role', 'user');

        // Optional filter by phone status (e.g., ?phone_status=active)
        if ($request->filled('phone_status')) {
            $query->whereHas('whatsItems', function ($q) use ($request) {
                $q->where('phone_status', $request->phone_status);
            });
        }

        // Optional search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('restuarant_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $isPaginated = $request->boolean('paginate', true);
        $perPage = $request->integer('per_page', 15);

        $users = $isPaginated
            ? $query->paginate($perPage)
            : $query->get();

        $response = [
            'status' => true,
            'data' => $users,
        ];

        if ($users instanceof LengthAwarePaginator) {
            $response['pagination'] = [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
                'has_more' => $users->hasMorePages(),
            ];
        }

        return response()->json($response);
    }

    public function admins(Request $request): JsonResponse
    {
        return app(AdminController::class)->index($request);
    }

    /**
     * Store a newly created user and automatically connect with Meta Cloud API.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string|max:50|unique:users,phone',
            'password' => 'required|string|min:6',
            'restuarant_name' => 'required|string|max:255',
            'name' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255|unique:users,email',
            'auto_request_code' => 'sometimes|boolean',
            'code_method' => 'sometimes|in:SMS,VOICE',
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $validated['role'] = 'user'; // Automatic user role

        if (empty($validated['name'])) {
            $validated['name'] = $validated['restuarant_name'];
        }

        // -------------------------------------------------------------
        // Automated Meta Onboarding
        // -------------------------------------------------------------
        $metaResponseInfo = null;
        $phoneNumberId = null;

        if ($this->metaService->isConfigured()) {
            // 1. Add phone number to Meta WABA (or retrieve existing)
            $addResult = $this->metaService->addPhoneNumber(
                phone: $validated['phone'],
                verifiedName: $validated['restuarant_name']
            );

            if ($addResult['success']) {
                $phoneNumberId = $addResult['phone_number_id'] ?? null;

                // 2. Automatically request verification code (OTP) via SMS or Voice
                $shouldSendCode = $request->boolean('auto_request_code', true);
                if ($phoneNumberId && $shouldSendCode) {
                    $codeMethod = $request->input('code_method', 'SMS');
                    $codeResult = $this->metaService->requestCode($phoneNumberId, $codeMethod);
                    $metaResponseInfo = [
                        'meta_registered' => true,
                        'phone_number_id' => $phoneNumberId,
                        'otp_sent' => $codeResult['success'],
                        'otp_message' => $codeResult['message'] ?? null,
                    ];
                } else {
                    $metaResponseInfo = [
                        'meta_registered' => true,
                        'phone_number_id' => $phoneNumberId,
                        'otp_sent' => false,
                    ];
                }
            } else {
                $metaResponseInfo = [
                    'meta_registered' => false,
                    'error' => $addResult['message'] ?? 'Meta API error.',
                ];
            }
        } else {
            $metaResponseInfo = [
                'meta_registered' => false,
                'notice' => 'Meta credentials (META_WABA_ID, META_SYSTEM_USER_TOKEN) are not configured in .env.',
            ];
        }

        $user = User::create([
            'phone' => $validated['phone'],
            'password' => $validated['password'],
            'restuarant_name' => $validated['restuarant_name'],
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'role' => 'user',
        ]);

        $whatsItem = WhatsItem::create([
            'user_id' => $user->id,
            'phone' => $validated['phone'],
            'phone_number_id' => $phoneNumberId,
            'waba_id' => $this->metaService->getWabaId(),
            'access_token' => $this->metaService->getSystemUserToken(),
            'phone_status' => 'pending_otp',
        ]);

        $user->load('whatsItems');

        return response()->json([
            'status' => true,
            'message' => 'User created successfully.',
            'data' => $user,
            'whats_item' => $whatsItem,
            'meta' => $metaResponseInfo,
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified user.
     */
    public function show(User $user): JsonResponse
    {
        if ($user->role !== 'user') {
            return response()->json([
                'status' => false,
                'message' => 'User not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $user->load(['whatsItems', 'messengerAccounts']);

        return response()->json([
            'status' => true,
            'data' => $user,
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        if ($user->role !== 'user') {
            return response()->json([
                'status' => false,
                'message' => 'User not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'phone' => 'sometimes|required|string|max:50|unique:users,phone,'.$user->id,
            'password' => 'sometimes|nullable|string|min:6',
            'restuarant_name' => 'sometimes|required|string|max:255',
            'name' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255|unique:users,email,'.$user->id,
            'phone_status' => 'sometimes|in:pending_otp,verified,active',
        ]);

        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        // Keep role strictly user
        $validated['role'] = 'user';

        if (isset($validated['phone_status'])) {
            $primaryItem = $user->whatsItems()->first();
            if ($primaryItem) {
                $primaryItem->update(['phone_status' => $validated['phone_status']]);
            }
            unset($validated['phone_status']);
        }

        $user->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'User updated successfully.',
            'data' => $user->fresh()->load('whatsItems'),
        ]);
    }

    /**
     * Remove the specified user.
     */
    public function destroy(User $user): JsonResponse
    {
        if ($user->role !== 'user') {
            return response()->json([
                'status' => false,
                'message' => 'User not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $user->delete();

        return response()->json([
            'status' => true,
            'message' => 'User deleted successfully.',
        ]);
    }

    /**
     * Request verification OTP code for user's phone number from Meta.
     */
    public function requestCode(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'whats_item_id' => 'sometimes|exists:whats_items,id',
            'code_method' => 'sometimes|in:SMS,VOICE',
            'language' => 'sometimes|string|max:10',
        ]);

        $whatsItem = $request->filled('whats_item_id')
            ? WhatsItem::where('user_id', $user->id)->findOrFail($request->whats_item_id)
            : $user->whatsItems()->first();

        if (! $whatsItem) {
            $whatsItem = WhatsItem::create([
                'user_id' => $user->id,
                'phone' => $user->phone,
                'phone_status' => 'pending_otp',
            ]);
        }

        Log::info('requestCode: initiated', [
            'user_id' => $user->id,
            'whats_item_id' => $whatsItem->id,
            'phone' => $whatsItem->phone,
            'has_phone_number_id' => ! empty($whatsItem->phone_number_id),
        ]);

        if (empty($whatsItem->phone_number_id)) {
            // Try to register phone number to Meta first if not already done
            Log::info('requestCode: phone_number_id missing, attempting to add phone to Meta.', [
                'user_id' => $user->id,
                'phone' => $whatsItem->phone,
            ]);

            $addResult = $this->metaService->addPhoneNumber(
                phone: $whatsItem->phone ?: $user->phone,
                verifiedName: $user->restuarant_name
            );

            if (! $addResult['success']) {
                Log::error('requestCode: failed to add phone to Meta WABA.', [
                    'user_id' => $user->id,
                    'phone' => $whatsItem->phone,
                    'error' => $addResult['message'] ?? 'unknown',
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Cannot request code: '.($addResult['message'] ?? 'Failed to add phone to Meta.'),
                ], Response::HTTP_BAD_REQUEST);
            }

            $whatsItem->update([
                'phone_number_id' => $addResult['phone_number_id'],
                'waba_id' => $this->metaService->getWabaId(),
                'access_token' => $whatsItem->access_token ?: $this->metaService->getSystemUserToken(),
            ]);

            Log::info('requestCode: phone added to Meta WABA.', [
                'user_id' => $user->id,
                'whats_item_id' => $whatsItem->id,
                'phone_number_id' => $addResult['phone_number_id'],
            ]);
        }

        $codeMethod = $validated['code_method'] ?? 'SMS';
        $language = $validated['language'] ?? 'ar';

        Log::info('requestCode: requesting OTP from Meta.', [
            'user_id' => $user->id,
            'whats_item_id' => $whatsItem->id,
            'phone_number_id' => $whatsItem->phone_number_id,
            'code_method' => $codeMethod,
            'language' => $language,
        ]);

        $result = $this->metaService->requestCode($whatsItem->phone_number_id, $codeMethod, $language);

        if ($result['success']) {
            Log::info('requestCode: OTP sent successfully.', [
                'user_id' => $user->id,
                'phone' => $whatsItem->phone,
            ]);

            return response()->json([
                'status' => true,
                'message' => "Verification code sent to {$whatsItem->phone} via {$codeMethod}.",
                'data' => $result['data'] ?? [],
                'whats_item' => $whatsItem->fresh(),
            ]);
        }

        Log::warning('requestCode: failed to send OTP.', [
            'user_id' => $user->id,
            'phone' => $whatsItem->phone,
            'error' => $result['message'] ?? 'unknown',
        ]);

        return response()->json([
            'status' => false,
            'message' => $result['message'] ?? 'Failed to send verification code.',
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * Verify OTP code and register phone number on WhatsApp Cloud API.
     */
    public function verifyAndRegister(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'whats_item_id' => 'sometimes|exists:whats_items,id',
            'code' => 'required|string|max:10',
            'pin' => 'required|string|size:6|regex:/^[0-9]+$/',
        ]);

        $whatsItem = $request->filled('whats_item_id')
            ? WhatsItem::where('user_id', $user->id)->findOrFail($request->whats_item_id)
            : $user->whatsItems()->first();

        if (! $whatsItem || empty($whatsItem->phone_number_id)) {
            Log::warning('verifyAndRegister: missing phone_number_id.', ['user_id' => $user->id]);

            return response()->json([
                'status' => false,
                'message' => 'WhatsApp item does not have a phone_number_id from Meta. Request a code first.',
            ], Response::HTTP_BAD_REQUEST);
        }

        Log::info('verifyAndRegister: initiated', [
            'user_id' => $user->id,
            'whats_item_id' => $whatsItem->id,
            'phone' => $whatsItem->phone,
            'phone_number_id' => $whatsItem->phone_number_id,
        ]);

        // 1. Verify the OTP code
        $verifyResult = $this->metaService->verifyCode($whatsItem->phone_number_id, $validated['code']);

        if (! $verifyResult['success']) {
            Log::warning('verifyAndRegister: OTP verification failed.', [
                'user_id' => $user->id,
                'error' => $verifyResult['message'] ?? 'unknown',
            ]);

            return response()->json([
                'status' => false,
                'message' => $verifyResult['message'] ?? 'Invalid verification code.',
            ], Response::HTTP_BAD_REQUEST);
        }

        Log::info('verifyAndRegister: OTP verified successfully.', ['user_id' => $user->id]);

        // 2. Register the phone number on Cloud API using the 6-digit PIN
        $registerResult = $this->metaService->registerNumber($whatsItem->phone_number_id, $validated['pin']);

        if (! $registerResult['success']) {
            $whatsItem->update(['phone_status' => 'verified']);

            Log::warning('verifyAndRegister: OTP verified but Cloud API registration failed.', [
                'user_id' => $user->id,
                'error' => $registerResult['message'] ?? 'unknown',
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Code verified, but failed to register on Cloud API: '.($registerResult['message'] ?? ''),
                'phone_status' => 'verified',
                'data' => $whatsItem->fresh(),
            ], Response::HTTP_BAD_REQUEST);
        }

        // 3. Mark whatsItem as active & verified
        $whatsItem->update([
            'phone_status' => 'active',
            'phone_verified_at' => now(),
            'access_token' => $whatsItem->access_token ?: $this->metaService->getSystemUserToken(),
        ]);

        Log::info('verifyAndRegister: phone registered and item activated.', [
            'user_id' => $user->id,
            'whats_item_id' => $whatsItem->id,
            'phone' => $whatsItem->phone,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Phone number verified and registered on WhatsApp Cloud API successfully!',
            'data' => $whatsItem->fresh(),
        ]);
    }

    /**
     * Sync phone number status directly from Meta Graph API.
     */
    public function syncMetaStatus(Request $request, User $user): JsonResponse
    {
        $whatsItem = $request->filled('whats_item_id')
            ? WhatsItem::where('user_id', $user->id)->findOrFail($request->whats_item_id)
            : $user->whatsItems()->first();

        if (! $whatsItem || empty($whatsItem->phone_number_id)) {
            Log::warning('syncMetaStatus: user has no phone_number_id.', ['user_id' => $user->id]);

            return response()->json([
                'status' => false,
                'message' => 'WhatsApp item does not have a phone_number_id from Meta.',
            ], Response::HTTP_BAD_REQUEST);
        }

        Log::info('syncMetaStatus: initiated', [
            'user_id' => $user->id,
            'whats_item_id' => $whatsItem->id,
            'phone_number_id' => $whatsItem->phone_number_id,
        ]);

        $details = $this->metaService->getPhoneNumberDetails($whatsItem->phone_number_id);

        if (! $details['success']) {
            Log::error('syncMetaStatus: failed to fetch details from Meta.', [
                'user_id' => $user->id,
                'phone_number_id' => $whatsItem->phone_number_id,
                'error' => $details['message'] ?? 'unknown',
            ]);

            return response()->json([
                'status' => false,
                'message' => $details['message'] ?? 'Failed to retrieve details from Meta.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $metaData = $details['data'] ?? [];
        $codeStatus = $metaData['code_verification_status'] ?? null;
        $status = $metaData['status'] ?? null;

        Log::info('syncMetaStatus: received Meta data.', [
            'user_id' => $user->id,
            'whats_item_id' => $whatsItem->id,
            'meta_status' => $status,
            'code_status' => $codeStatus,
        ]);

        // Auto-update whats_item phone_status based on Meta's status
        if ($status === 'CONNECTED' || $codeStatus === 'VERIFIED') {
            $whatsItem->update([
                'phone_status' => 'active',
                'phone_verified_at' => $whatsItem->phone_verified_at ?: now(),
            ]);

            Log::info('syncMetaStatus: whats_item phone_status updated to active.', ['whats_item_id' => $whatsItem->id]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Meta status synced successfully.',
            'data' => [
                'whats_item_id' => $whatsItem->id,
                'phone_status' => $whatsItem->fresh()->phone_status,
                'meta' => $metaData,
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MetaWhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
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
            'role' => 'sometimes|in:admin,user',
            'phone_status' => 'sometimes|string|max:50',
            'search' => 'sometimes|string|max:255',
            'paginate' => 'sometimes|boolean',
        ]);

        $query = User::latest();

        // Optional filter by role (e.g., ?role=user)
        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        // Optional filter by phone status (e.g., ?phone_status=active)
        if ($request->filled('phone_status')) {
            $query->where('phone_status', $request->phone_status);
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

    /**
     * Store a newly created user and automatically connect with Meta Cloud API.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string|max:50|unique:users,phone',
            'password' => 'required|string|min:6',
            'restuarant_name' => 'required|string|max:255',
            'ai_context' => 'sometimes|nullable|string',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'name' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255|unique:users,email',
            'role' => 'sometimes|in:admin,user',
            'auto_request_code' => 'sometimes|boolean',
            'code_method' => 'sometimes|in:SMS,VOICE',
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $validated['role'] = $validated['role'] ?? 'user';

        if (empty($validated['name'])) {
            $validated['name'] = $validated['restuarant_name'];
        }

        // Default phone status
        $validated['phone_status'] = 'pending_otp';

        // -------------------------------------------------------------
        // Automated Meta Onboarding
        // -------------------------------------------------------------
        $metaResponseInfo = null;

        if ($this->metaService->isConfigured()) {
            $validated['access_token'] = $this->metaService->getSystemUserToken();
            $validated['waba_id'] = $this->metaService->getWabaId();

            // 1. Add phone number to Meta WABA (or retrieve existing)
            $addResult = $this->metaService->addPhoneNumber(
                phone: $validated['phone'],
                verifiedName: $validated['restuarant_name']
            );

            if ($addResult['success']) {
                $phoneNumberId = $addResult['phone_number_id'] ?? null;
                $validated['phone_number_id'] = $phoneNumberId;

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

        $user = User::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'User created successfully.',
            'data' => $user,
            'meta' => $metaResponseInfo,
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified user.
     */
    public function show(User $user): JsonResponse
    {
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
        $validated = $request->validate([
            'phone' => 'sometimes|required|string|max:50|unique:users,phone,'.$user->id,
            'password' => 'sometimes|nullable|string|min:6',
            'restuarant_name' => 'sometimes|required|string|max:255',
            'ai_context' => 'sometimes|nullable|string',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'name' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255|unique:users,email,'.$user->id,
            'role' => 'sometimes|in:admin,user',
            'phone_number_id' => 'sometimes|nullable|string|max:255',
            'access_token' => 'sometimes|nullable|string|max:500',
            'waba_id' => 'sometimes|nullable|string|max:255',
            'phone_status' => 'sometimes|in:pending_otp,verified,active',
        ]);

        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $user->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'User updated successfully.',
            'data' => $user,
        ]);
    }

    /**
     * Remove the specified user.
     */
    public function destroy(User $user): JsonResponse
    {
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
            'code_method' => 'sometimes|in:SMS,VOICE',
            'language' => 'sometimes|string|max:10',
        ]);

        if (empty($user->phone_number_id)) {
            // Try to register phone number to Meta first if not already done
            $addResult = $this->metaService->addPhoneNumber(
                phone: $user->phone,
                verifiedName: $user->restuarant_name
            );

            if (! $addResult['success']) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot request code: '.($addResult['message'] ?? 'Failed to add phone to Meta.'),
                ], Response::HTTP_BAD_REQUEST);
            }

            $user->update([
                'phone_number_id' => $addResult['phone_number_id'],
                'waba_id' => $this->metaService->getWabaId(),
                'access_token' => $user->access_token ?: $this->metaService->getSystemUserToken(),
            ]);
        }

        $codeMethod = $validated['code_method'] ?? 'SMS';
        $language = $validated['language'] ?? 'ar';

        $result = $this->metaService->requestCode($user->phone_number_id, $codeMethod, $language);

        if ($result['success']) {
            return response()->json([
                'status' => true,
                'message' => "Verification code sent to {$user->phone} via {$codeMethod}.",
                'data' => $result['data'] ?? [],
            ]);
        }

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
            'code' => 'required|string|max:10',
            'pin' => 'required|string|size:6|regex:/^[0-9]+$/',
        ]);

        if (empty($user->phone_number_id)) {
            return response()->json([
                'status' => false,
                'message' => 'User does not have a phone_number_id from Meta. Request a code first.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // 1. Verify the OTP code
        $verifyResult = $this->metaService->verifyCode($user->phone_number_id, $validated['code']);

        if (! $verifyResult['success']) {
            return response()->json([
                'status' => false,
                'message' => $verifyResult['message'] ?? 'Invalid verification code.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // 2. Register the phone number on Cloud API using the 6-digit PIN
        $registerResult = $this->metaService->registerNumber($user->phone_number_id, $validated['pin']);

        if (! $registerResult['success']) {
            $user->update(['phone_status' => 'verified']);

            return response()->json([
                'status' => false,
                'message' => 'Code verified, but failed to register on Cloud API: '.($registerResult['message'] ?? ''),
                'phone_status' => 'verified',
            ], Response::HTTP_BAD_REQUEST);
        }

        // 3. Mark user as active & verified
        $user->update([
            'phone_status' => 'active',
            'phone_verified_at' => now(),
            'access_token' => $user->access_token ?: $this->metaService->getSystemUserToken(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Phone number verified and registered on WhatsApp Cloud API successfully!',
            'data' => $user->fresh(),
        ]);
    }

    /**
     * Sync phone number status directly from Meta Graph API.
     */
    public function syncMetaStatus(User $user): JsonResponse
    {
        if (empty($user->phone_number_id)) {
            return response()->json([
                'status' => false,
                'message' => 'User does not have a phone_number_id from Meta.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $details = $this->metaService->getPhoneNumberDetails($user->phone_number_id);

        if (! $details['success']) {
            return response()->json([
                'status' => false,
                'message' => $details['message'] ?? 'Failed to retrieve details from Meta.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $metaData = $details['data'] ?? [];
        $codeStatus = $metaData['code_verification_status'] ?? null;
        $status = $metaData['status'] ?? null;

        // Auto-update user phone_status based on Meta's status
        if ($status === 'CONNECTED' || $codeStatus === 'VERIFIED') {
            $user->update([
                'phone_status' => 'active',
                'phone_verified_at' => $user->phone_verified_at ?: now(),
            ]);
        }

        return response()->json([
            'status' => true,
            'message' => 'Meta status synchronized successfully.',
            'meta' => $metaData,
            'user' => $user->fresh(),
        ]);
    }
}

<?php

namespace App\Http\Controllers\api\auth;

use App\Http\Controllers\Controller;
use App\Mail\ActivationCodeMail;
use App\Mail\ResetPasswordCodeMail;
use App\Models\InstagramItem;
use App\Models\MessengerAccount;
use App\Models\Order;
use App\Models\User;
use App\Services\MetaPageTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LoginController extends Controller
{
    /**
     * Handle user signup and send activation code via email.
     */
    public function signup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|string|max:255',
            'password' => 'required|string|min:6',
            'phone' => 'required|string|max:20',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user && ($user->is_active || $user->role === 'admin')) {
            return response()->json([
                'status' => false,
                'message' => 'Email is already registered.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $phoneExists = User::where('phone', $validated['phone'])
            ->when($user, function ($query) use ($user) {
                $query->where('id', '!=', $user->id);
            })
            ->exists();

        if ($phoneExists) {
            return response()->json([
                'status' => false,
                'message' => 'Phone number is already registered.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $code = (string) random_int(100000, 999999);

        if ($user) {
            $user->update([
                'name' => $validated['name'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'],
                'code' => $code,
                'is_active' => false,
            ]);
        } else {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'phone' => $validated['phone'],
                'code' => $code,
                'is_active' => false,
                'role' => 'user',
            ]);
        }

        try {
            Mail::to($user->email)->send(new ActivationCodeMail($code, $user->name));
        } catch (\Throwable $e) {
            Log::error('Failed to send activation email', [
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to send activation email. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json([
            'status' => true,
            'message' => 'Verification code sent to your email successfully.',
        ]);
    }

    /**
     * Activate user account via verification code.
     */
    public function active_account(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|string|max:255',
            'code' => 'required|string|max:20',
        ]);

        $user = User::where('email', $validated['email'])
            ->where('code', $validated['code'])
            ->whereNotNull('code')
            ->first();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired activation code.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $user->code = null;
        $user->is_active = true;
        $user->save();

        $tokenName = 'user_auth_token';
        $token = $user->createToken($tokenName)->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'Your account has been activated successfully.',
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Send password reset code via email.
     */
    public function forget_password(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|string|max:255',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'Email address not found.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $code = (string) random_int(100000, 999999);
        $user->code = $code;
        $user->save();

        try {
            Mail::to($user->email)->send(new ResetPasswordCodeMail($code, $user->name ?? 'User'));
        } catch (\Throwable $e) {
            Log::error('Failed to send reset password email', [
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to send reset code email. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json([
            'status' => true,
            'message' => 'Password reset code sent to your email successfully.',
        ]);
    }

    /**
     * Check if verification code is valid for given email.
     */
    public function check_code(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|string|max:255',
            'code' => 'required|string|max:20',
        ]);

        $isValid = User::where('email', $validated['email'])
            ->where('code', $validated['code'])
            ->whereNotNull('code')
            ->exists();

        return response()->json([
            'status' => $isValid,
            'is_valid' => $isValid,
            'message' => $isValid ? 'Code is valid.' : 'Invalid or expired code.',
        ]);
    }

    /**
     * Reset/change password using email and verified code.
     */
    public function change_password(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|string|max:255',
            'code' => 'required|string|max:20',
            'password' => 'required|string|min:6',
        ]);

        if ($request->filled('password_confirmation') && $request->password !== $request->password_confirmation) {
            return response()->json([
                'status' => false,
                'message' => 'Password confirmation does not match.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = User::where('email', $validated['email'])
            ->where('code', $validated['code'])
            ->whereNotNull('code')
            ->first();

        if (! $user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired code.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
            'code' => null,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Password changed successfully.',
        ]);
    }

    /**
     * Handle admin login.
     */
    public function adminLogin(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required_without_all:phone,login|nullable|string',
            'password' => 'required|string',
        ]);

        return $this->authenticateAdmin($request, 'admin');
    }

    /**
     * Handle user login.
     */
    public function userLogin(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required_without_all:email,login|nullable|string',
            'password' => 'required|string',
        ]);

        return $this->authenticate($request, 'user');
    }

    /**
     * Handle logout (revoke current Sanctum access token).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Handle Facebook OAuth login / signup.
     *
     * The mobile/frontend obtains a Facebook user access token via Facebook SDK,
     * then sends it here. We verify it against Graph API, then create or link the user.
     *
     * @bodyParam access_token string required Facebook User Access Token from SDK. Example: EAABsb...
     */
    public function facebookLogin(Request $request): JsonResponse
    {
        $request->validate([
            'access_token' => 'required|string',
        ]);

        $fbToken = $request->input('access_token');
        $graphVersion = config('services.meta.graph_version', 'v21.0');

        // 1. Verify token & fetch user info from Graph API
        try {
            $meResponse = Http::withToken($fbToken)
                ->withOptions([
                    'curl' => [
                        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    ],
                ])
                ->timeout(30)
                ->retry(2, 200, throw: false)
                ->get("https://graph.facebook.com/{$graphVersion}/me", [
                    'fields' => 'id,name,email',
                    'access_token' => $fbToken,
                ]);
        } catch (\Throwable $e) {
            Log::error('Facebook login: connection error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Unable to connect to Facebook for verification. Please try again.',
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        if (! $meResponse->successful()) {
            Log::warning('Facebook login: Graph API /me failed', [
                'status' => $meResponse->status(),
                'body' => $meResponse->json(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Invalid Facebook access token.',
                'error' => $meResponse->json('error.message'),
            ], Response::HTTP_UNAUTHORIZED);
        }

        $fbData = $meResponse->json();
        $facebookId = (string) ($fbData['id'] ?? '');
        $fbName = $fbData['name'] ?? null;
        $fbEmail = $fbData['email'] ?? null;

        if (! $facebookId) {
            return response()->json([
                'status' => false,
                'message' => 'Could not retrieve Facebook user ID.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // 2. Find or create user
        /** @var User|null $user */
        $user = $request->user() ?: auth('sanctum')->user();

        if (! $user && auth()->check()) {
            $user = auth()->user();
        }

        if (! $user) {
            $user = User::where('facebook_id', $facebookId)->first();
        }

        if (! $user && $fbEmail) {
            // Link existing account that has the same email
            $user = User::where('email', $fbEmail)->first();
        }

        $isLinkingExistingAccount = (bool) ($request->user() || auth('sanctum')->check());

        // Exchange short-lived token for long-lived token (60 days) if Meta App credentials exist
        /** @var MetaPageTokenService $tokenService */
        $tokenService = app(MetaPageTokenService::class);
        $fbToken = $tokenService->exchangeForLongLivedToken($fbToken);

        if ($user) {
            // If another user already has this facebook_id, unlink it first
            // to avoid MySQL 1062 Duplicate entry unique constraint violation
            $previousUser = User::where('facebook_id', $facebookId)
                ->where('id', '!=', $user->id)
                ->first();

            if ($previousUser) {
                $previousUser->update([
                    'facebook_id' => null,
                    'facebook_access_token' => null,
                ]);

                // Reassign existing MessengerAccounts, InstagramItems and Orders to the new user
                MessengerAccount::where('user_id', $previousUser->id)->update(['user_id' => $user->id]);
                InstagramItem::where('user_id', $previousUser->id)->update(['user_id' => $user->id]);
                Order::where('user_id', $previousUser->id)->update(['user_id' => $user->id]);

                Log::info('Facebook login: transferred Facebook link and assets from previous user', [
                    'from_user_id' => $previousUser->id,
                    'to_user_id' => $user->id,
                    'facebook_id' => $facebookId,
                ]);
            }

            // Update facebook token on every login/link (tokens refresh)
            $user->update([
                'facebook_id' => $facebookId,
                'facebook_access_token' => $fbToken,
            ]);
        } else {
            // Create new user — phone is nullable so we generate a placeholder
            $user = User::create([
                'name' => $fbName ?? 'Facebook User',
                'email' => $fbEmail,
                'phone' => 'fb_'.$facebookId,
                'password' => Hash::make(Str::random(32)),
                'restuarant_name' => $fbName ?? 'My Restaurant',
                'role' => 'user',
                'facebook_id' => $facebookId,
                'facebook_access_token' => $fbToken,
            ]);
        }

        Log::info('Facebook login successful', [
            'user_id' => $user->id,
            'facebook_id' => $facebookId,
            'is_new' => $user->wasRecentlyCreated,
        ]);

        // Automatically update all user's Facebook pages and Instagram items with fresh tokens
        app(MetaPageTokenService::class)->syncUserPagesAndTokens($user, $fbToken);

        $token = $user->createToken('facebook_auth_token')->plainTextToken;

        $message = $user->wasRecentlyCreated
            ? 'Account created via Facebook.'
            : ($isLinkingExistingAccount ? 'Account linked to Facebook successfully.' : 'Logged in via Facebook.');

        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => [
                'user' => $user,
                'token' => $token,
                'token_type' => 'Bearer',
                'is_new' => $user->wasRecentlyCreated,
            ],
        ]);
    }

    /**
     * Authenticate user credentials and verify role.
     */
    private function authenticateAdmin(Request $request, string $requiredRole): JsonResponse
    {
        $request->validate([
            'email' => 'required_without_all:phone,login|nullable|string',
            'password' => 'required|string',
        ]);

        $throttleKey = $this->loginThrottleKey($request, $requiredRole);

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $minutes = max(1, (int) ceil($seconds / 60));

            return response()->json([
                'status' => false,
                'message' => "Too many failed login attempts. Please wait {$minutes} minute(s) before trying again.",
                'retry_after_seconds' => $seconds,
                'retry_after_minutes' => $minutes,
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $user = null;

        if ($request->filled('email')) {
            $user = User::where('email', $request->email)->first();
        } elseif ($request->filled('phone')) {
            $user = User::where('phone', $request->phone)->first();
        } elseif ($request->filled('login')) {
            $login = $request->login;
            $user = User::where('email', $login)->orWhere('phone', $login)->first();
        }

        if (! $user || ! Hash::check($request->password, $user->password)) {
            RateLimiter::hit($throttleKey, 300);

            return response()->json([
                'status' => false,
                'message' => 'Invalid credentials.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ($user->role !== $requiredRole) {
            RateLimiter::hit($throttleKey, 300);

            return response()->json([
                'status' => false,
                'message' => "Forbidden: You must have the '{$requiredRole}' role to log in here.",
            ], Response::HTTP_FORBIDDEN);
        }

        RateLimiter::clear($throttleKey);

        $tokenName = "{$requiredRole}_auth_token";
        $token = $user->createToken($tokenName)->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'Logged in successfully.',
            'data' => [
                'user' => $user,
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    private function authenticate(Request $request, string $requiredRole): JsonResponse
    {
        $request->validate([
            'phone' => 'required_without_all:email,login|nullable|string',
            'password' => 'required|string',
        ]);

        $throttleKey = $this->loginThrottleKey($request, $requiredRole);

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $minutes = max(1, (int) ceil($seconds / 60));

            return response()->json([
                'status' => false,
                'message' => "Too many failed login attempts. Please wait {$minutes} minute(s) before trying again.",
                'retry_after_seconds' => $seconds,
                'retry_after_minutes' => $minutes,
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $user = null;

        if ($request->filled('email')) {
            $user = User::where('email', $request->email)->first();
        } elseif ($request->filled('phone')) {
            $user = User::where('phone', $request->phone)->first();
        } elseif ($request->filled('login')) {
            $login = $request->login;
            $user = User::where('email', $login)->orWhere('phone', $login)->first();
        }

        if (! $user || ! Hash::check($request->password, $user->password)) {
            RateLimiter::hit($throttleKey, 300);

            return response()->json([
                'status' => false,
                'message' => 'Invalid credentials.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ($user->role !== $requiredRole) {
            RateLimiter::hit($throttleKey, 300);

            return response()->json([
                'status' => false,
                'message' => "Forbidden: You must have the '{$requiredRole}' role to log in here.",
            ], Response::HTTP_FORBIDDEN);
        }

        RateLimiter::clear($throttleKey);

        $tokenName = "{$requiredRole}_auth_token";
        $token = $user->createToken($tokenName)->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'Logged in successfully.',
            'data' => [
                'user' => $user,
                'token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    /**
     * Get throttle key for failed login attempts.
     */
    private function loginThrottleKey(Request $request, string $requiredRole): string
    {
        $login = Str::lower((string) ($request->input('email') ?? $request->input('phone') ?? $request->input('login') ?? ''));

        return "login_error:{$requiredRole}:".($login !== '' ? "{$login}|" : '').($request->ip() ?: '127.0.0.1');
    }
}

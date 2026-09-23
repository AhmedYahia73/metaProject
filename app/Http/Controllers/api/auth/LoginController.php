<?php

namespace App\Http\Controllers\api\auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LoginController extends Controller
{
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
        $meResponse = Http::get("https://graph.facebook.com/{$graphVersion}/me", [
            'fields' => 'id,name,email',
            'access_token' => $fbToken,
        ]);

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
        $user = User::where('facebook_id', $facebookId)->first();

        if (! $user && $fbEmail) {
            // Link existing account that has the same email
            $user = User::where('email', $fbEmail)->first();
        }

        if ($user) {
            // Update facebook token on every login (tokens refresh)
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

        $token = $user->createToken('facebook_auth_token')->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => $user->wasRecentlyCreated ? 'Account created via Facebook.' : 'Logged in via Facebook.',
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
            return response()->json([
                'status' => false,
                'message' => 'Invalid credentials.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ($user->role !== $requiredRole) {
            return response()->json([
                'status' => false,
                'message' => "Forbidden: You must have the '{$requiredRole}' role to log in here.",
            ], Response::HTTP_FORBIDDEN);
        }

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
            return response()->json([
                'status' => false,
                'message' => 'Invalid credentials.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if ($user->role !== $requiredRole) {
            return response()->json([
                'status' => false,
                'message' => "Forbidden: You must have the '{$requiredRole}' role to log in here.",
            ], Response::HTTP_FORBIDDEN);
        }

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
}

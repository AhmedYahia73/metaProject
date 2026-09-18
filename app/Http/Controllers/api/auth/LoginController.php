<?php

namespace App\Http\Controllers\api\auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ApiLoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class LoginController extends Controller
{
    /**
     * Handle admin login.
     */
    public function adminLogin(ApiLoginRequest $request): JsonResponse
    {
        return $this->authenticate($request, 'admin');
    }

    /**
     * Handle user login.
     */
    public function userLogin(ApiLoginRequest $request): JsonResponse
    {
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
     * Authenticate user credentials and verify role.
     */
    private function authenticate(Request $request, string $requiredRole): JsonResponse
    {
        $request->validate([
            'email' => 'required_without_all:phone,login|nullable|string',
            'phone' => 'required_without_all:email,login|nullable|string',
            'login' => 'required_without_all:email,phone|nullable|string',
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

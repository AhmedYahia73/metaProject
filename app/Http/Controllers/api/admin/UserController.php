<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    /**
     * Display a listing of users.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::latest();

        // Optional filter by role (e.g., ?role=user)
        if ($request->filled('role')) {
            $query->where('role', $request->role);
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

        $users = $request->boolean('paginate', true)
            ? $query->paginate($request->integer('per_page', 15))
            : $query->get();

        return response()->json([
            'status' => true,
            'data' => $users,
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string|max:50|unique:users,phone',
            'password' => 'required|string|min:6',
            'url' => 'required|string|max:255',
            'restuarant_name' => 'required|string|max:255',
            'ai_context' => 'sometimes|nullable|string',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'name' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255|unique:users,email',
            'role' => 'sometimes|in:admin,user',  
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $validated['role'] = $validated['role'] ?? 'user';

        if (empty($validated['name'])) {
            $validated['name'] = $validated['restuarant_name'];
        }

        $user = User::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'User created successfully.',
            'data' => $user,
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
            'phone' => 'sometimes|required|string|max:50|unique:users,phone,' . $user->id,
            'password' => 'sometimes|nullable|string|min:6',
            'restuarant_name' => 'sometimes|required|string|max:255',
            'url' => 'required|string|max:255',
            'ai_context' => 'sometimes|nullable|string',
            'android_link' => 'sometimes|nullable|string|max:500',
            'ios_link' => 'sometimes|nullable|string|max:500',
            'name' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255|unique:users,email,' . $user->id,
            'role' => 'sometimes|in:admin,user', 
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
}

<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AdminController extends Controller
{
    /**
     * Display a listing of admin users (paginated).
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Number of admins per page (default: 15). Example: 15
     * @queryParam search string Search in name, email, or phone. Example: Ahmed
     * @queryParam paginate boolean Whether to paginate the results (default: true). Example: true
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'search' => 'sometimes|string|max:255',
            'paginate' => 'sometimes|boolean',
        ]);

        $query = User::
        select('id', 'name', 'email', 'phone')
        ->latest()->where('role', 'admin');

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $isPaginated = $request->boolean('paginate', true);
        $perPage = $request->integer('per_page', 15);

        $admins = $isPaginated
            ? $query->paginate($perPage)
            : $query->get();

        $response = [
            'status' => true,
            'data' => $admins,
        ];

        if ($admins instanceof LengthAwarePaginator) {
            $response['pagination'] = [
                'current_page' => $admins->currentPage(),
                'last_page' => $admins->lastPage(),
                'per_page' => $admins->perPage(),
                'total' => $admins->total(),
                'from' => $admins->firstItem(),
                'to' => $admins->lastItem(),
                'has_more' => $admins->hasMorePages(),
            ];
        }

        return response()->json($response);
    }

    /**
     * Store a newly created admin user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:6',
        ]);

        $admin = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'admin', // Automatic admin role
            'phone_status' => 'active',
        ]);

        Log::info('Admin created successfully', [
            'admin_id' => $admin->id,
            'email' => $admin->email,
            'created_by' => auth()->id(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Admin created successfully.',
            'data' => $admin,
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified admin user.
     */
    public function show(User $admin): JsonResponse
    {
        if ($admin->role !== 'admin') {
            return response()->json([
                'status' => false,
                'message' => 'Admin not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'status' => true,
            'data' => $admin,
        ]);
    }

    /**
     * Update the specified admin user.
     */
    public function update(Request $request, User $admin): JsonResponse
    {
        if ($admin->role !== 'admin') {
            return response()->json([
                'status' => false,
                'message' => 'Admin not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255|unique:users,email,'.$admin->id,
            'password' => 'sometimes|nullable|string|min:6',
        ]);

        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        // Keep role strictly admin
        $validated['role'] = 'admin';

        $admin->update($validated);

        Log::info('Admin updated successfully', [
            'admin_id' => $admin->id,
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Admin updated successfully.',
            'data' => $admin->fresh(),
        ]);
    }

    /**
     * Remove the specified admin user.
     */
    public function destroy(User $admin): JsonResponse
    {
        if ($admin->role !== 'admin') {
            return response()->json([
                'status' => false,
                'message' => 'Admin not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        // Prevent admin from deleting their own account
        if (auth()->id() === $admin->id) {
            return response()->json([
                'status' => false,
                'message' => 'You cannot delete your own admin account.',
            ], Response::HTTP_FORBIDDEN);
        }

        $admin->delete();

        Log::info('Admin deleted successfully', [
            'admin_id' => $admin->id,
            'deleted_by' => auth()->id(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Admin deleted successfully.',
        ]);
    }
}


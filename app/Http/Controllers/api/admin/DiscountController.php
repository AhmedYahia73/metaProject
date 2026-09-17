<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DiscountController extends Controller
{
    /**
     * Display a listing of the discounts.
     */
    public function index(Request $request): JsonResponse
    {
        $discounts = $request->boolean('paginate')
            ? Discount::latest()->paginate($request->integer('per_page', 15))
            : Discount::latest()->get();

        return response()->json([
            'status' => true,
            'data' => $discounts,
        ]);
    }

    /**
     * Return a simple list of discounts (id and name only) for dropdowns/selects.
     */
    public function list(): JsonResponse
    {
        $discounts = Discount::select('id', 'name')->latest()->get();

        return response()->json([
            'status' => true,
            'data' => $discounts,
        ]);
    }

    /**
     * Store a newly created discount.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'type' => 'required|in:percentage,value',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $discount = Discount::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Discount created successfully.',
            'data' => $discount,
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified discount.
     */
    public function show(Discount $discount): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => $discount,
        ]);
    }

    /**
     * Update the specified discount.
     */
    public function update(Request $request, Discount $discount): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'amount' => 'sometimes|required|numeric|min:0',
            'type' => 'sometimes|required|in:percentage,value',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $discount->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Discount updated successfully.',
            'data' => $discount,
        ]);
    }

    /**
     * Remove the specified discount.
     */
    public function destroy(Discount $discount): JsonResponse
    {
        $discount->delete();

        return response()->json([
            'status' => true,
            'message' => 'Discount deleted successfully.',
        ]);
    }
}

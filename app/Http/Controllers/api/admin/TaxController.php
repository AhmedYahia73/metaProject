<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\Tax;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TaxController extends Controller
{
    /**
     * Display a listing of the taxes.
     */
    public function index(Request $request): JsonResponse
    {
        $taxes = $request->boolean('paginate')
            ? Tax::latest()->paginate($request->integer('per_page', 15))
            : Tax::latest()->get();

        return response()->json([
            'status' => true,
            'data' => $taxes,
        ]);
    }

    /**
     * Return a simple list of taxes (id and name only) for dropdowns/selects.
     */
    public function list(): JsonResponse
    {
        $taxes = Tax::select('id', 'name')->latest()->get();

        return response()->json([
            'status' => true,
            'data' => $taxes,
        ]);
    }

    /**
     * Store a newly created tax.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'type' => 'required|in:percentage,value',
        ]);

        $tax = Tax::create($validated);

        return response()->json([
            'status' => true,
            'message' => 'Tax created successfully.',
            'data' => $tax,
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified tax.
     */
    public function show(Tax $tax): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => $tax,
        ]);
    }

    /**
     * Update the specified tax.
     */
    public function update(Request $request, Tax $tax): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'amount' => 'sometimes|required|numeric|min:0',
            'type' => 'sometimes|required|in:percentage,value',
        ]);

        $tax->update($validated);

        return response()->json([
            'status' => true,
            'message' => 'Tax updated successfully.',
            'data' => $tax,
        ]);
    }

    /**
     * Remove the specified tax.
     */
    public function destroy(Tax $tax): JsonResponse
    {
        $tax->delete();

        return response()->json([
            'status' => true,
            'message' => 'Tax deleted successfully.',
        ]);
    }
}

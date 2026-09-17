<?php

namespace App\Http\Controllers\api\admin;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\Package;
use App\Models\Tax;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PackageController extends Controller
{
    /**
     * Display a listing of the packages.
     */
    public function index(Request $request): JsonResponse
    {
        $packages = $request->boolean('paginate')
            ? Package::with(['discount', 'tax'])->latest()->paginate($request->integer('per_page', 15))
            : Package::with(['discount', 'tax'])->latest()->get();

        return response()->json([
            'status' => true,
            'data' => $packages,
        ]);
    }

    /**
     * Return list of id and name for both taxes and discounts for dropdowns/selects.
     */
    public function taxAndDiscountList(): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => [
                'discounts' => Discount::select('id', 'name')->latest()->get(),
                'taxes' => Tax::select('id', 'name')->latest()->get(),
            ],
        ]);
    }

    /**
     * Store a newly created package.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|array',
            'name.en' => 'required|string|max:255',
            'name.ar' => 'required|string|max:255',
            'msg_number' => 'required|integer|min:0',
            'price' => 'required|numeric|min:0',
            'discount_id' => 'nullable|exists:discounts,id',
            'tax_id' => 'nullable|exists:taxes,id',
            'months' => 'required|integer|min:1',
        ]);

        $package = Package::create($validated);
        $package->load(['discount', 'tax']);

        return response()->json([
            'status' => true,
            'message' => 'Package created successfully.',
            'data' => $package,
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified package.
     */
    public function show(Package $package): JsonResponse
    {
        $package->load(['discount', 'tax']);

        return response()->json([
            'status' => true,
            'data' => $package,
        ]);
    }

    /**
     * Update the specified package.
     */
    public function update(Request $request, Package $package): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|array',
            'name.en' => 'required_with:name|string|max:255',
            'name.ar' => 'required_with:name|string|max:255',
            'msg_number' => 'sometimes|required|integer|min:0',
            'price' => 'sometimes|required|numeric|min:0',
            'discount_id' => 'nullable|exists:discounts,id',
            'tax_id' => 'nullable|exists:taxes,id',
            'months' => 'sometimes|required|integer|min:1',
        ]);

        $package->update($validated);
        $package->load(['discount', 'tax']);

        return response()->json([
            'status' => true,
            'message' => 'Package updated successfully.',
            'data' => $package,
        ]);
    }

    /**
     * Remove the specified package.
     */
    public function destroy(Package $package): JsonResponse
    {
        $package->delete();

        return response()->json([
            'status' => true,
            'message' => 'Package deleted successfully.',
        ]);
    }
}

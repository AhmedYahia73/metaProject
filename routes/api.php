<?php

use App\Http\Controllers\api\admin\DiscountController;
use App\Http\Controllers\api\admin\OrderController;
use App\Http\Controllers\api\admin\PackageController;
use App\Http\Controllers\api\admin\TaxController;
use App\Http\Controllers\api\auth\LoginController;
use App\Http\Controllers\api\HomeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| General API Routes
|--------------------------------------------------------------------------
*/
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/web-hook', [HomeController::class, 'web_hook']);
Route::post('/web-hook', [HomeController::class, 'web_hook']);

/*
|--------------------------------------------------------------------------
| Authentication Routes (Admin & User Login)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('admin/login', [LoginController::class, 'adminLogin']);
    Route::post('user/login', [LoginController::class, 'userLogin']);
    Route::post('logout', [LoginController::class, 'logout'])->middleware('auth:sanctum');
});

/*
|--------------------------------------------------------------------------
| Admin Routes (Protected by auth:sanctum & admin middleware)
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // Lookup endpoint for dropdowns (id & name for taxes and discounts)
    Route::get('tax-and-discount-list', [PackageController::class, 'taxAndDiscountList']);

    // Discounts CRUD & simple list
    Route::get('discounts/list', [DiscountController::class, 'list']);
    Route::apiResource('discounts', DiscountController::class);

    // Taxes CRUD & simple list
    Route::get('taxes/list', [TaxController::class, 'list']);
    Route::apiResource('taxes', TaxController::class);

    // Packages CRUD
    Route::apiResource('packages', PackageController::class);

    // Orders Management & Select Lists
    Route::get('orders/lists', [OrderController::class, 'lists']);
    Route::get('orders/lookup', [OrderController::class, 'lists']);
    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
});
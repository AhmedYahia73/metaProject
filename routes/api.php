<?php

use App\Http\Controllers\api\admin\AdminController;
use App\Http\Controllers\api\admin\DashboardController;
use App\Http\Controllers\api\admin\DiscountController;
use App\Http\Controllers\api\admin\OrderController;
use App\Http\Controllers\api\admin\PackageController;
use App\Http\Controllers\api\admin\SettingController;
use App\Http\Controllers\api\admin\TaxController;
use App\Http\Controllers\api\admin\UserController;
use App\Http\Controllers\api\auth\LoginController;
use App\Http\Controllers\api\HomeController;
use App\Http\Controllers\api\user\HomeController as UserHomeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| General API Routes
|--------------------------------------------------------------------------
*/
/**
 * Get currently authenticated user.
 *
 * @response array{id: int, name: string, email: string, phone: string, restuarant_name: string, role: string}
 */
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/web-hook', [HomeController::class, 'web_hook']);
Route::post('/web-hook', [HomeController::class, 'web_hook']);
Route::view('/privacy-policy', 'privacy-policy');

/*
|--------------------------------------------------------------------------
| User / Public Routes (Without Auth)
|--------------------------------------------------------------------------
*/
Route::prefix('user')->group(function () {
    Route::get('dashboard', [UserHomeController::class, 'index'])->middleware(['auth:sanctum', 'user']);
    Route::get('packages', [UserHomeController::class, 'packages']);
    Route::post('contact-us', [UserHomeController::class, 'contactUs'])
        ->middleware('throttle:contact-us');
});

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
    // Dashboard Statistics
    Route::get('user_lists', [DashboardController::class, 'user_lists']);
    Route::get('dashboard', [DashboardController::class, 'index']);

    // Lookup endpoint for dropdowns (id & name for taxes and discounts)
    Route::get('tax-and-discount-list', [PackageController::class, 'taxAndDiscountList']);

    // Discounts CRUD & simple list
    Route::apiResource('discounts', DiscountController::class);

    // Taxes CRUD & simple list
    Route::apiResource('taxes', TaxController::class);

    // Packages CRUD
    Route::apiResource('packages', PackageController::class);

    // Orders Management & Select Lists
    Route::get('orders/lists', [OrderController::class, 'lists']);
    Route::get('orders/lookup', [OrderController::class, 'lists']);
    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::get('orders/{order}', [OrderController::class, 'show']);

    // Settings (AI Context)
    Route::get('settings/ai-context', [SettingController::class, 'getAiContext']);
    Route::post('settings/ai-context', [SettingController::class, 'setAiContext']);

    // Admins Management (Automatic role: admin)
    Route::apiResource('admins', AdminController::class);

    // Users / Restaurants Management (Automatic role: user) & Meta WhatsApp Onboarding
    Route::post('users/{user}/request-code', [UserController::class, 'requestCode']);
    Route::post('users/{user}/verify-and-register', [UserController::class, 'verifyAndRegister']);
    Route::get('users/{user}/meta-status', [UserController::class, 'syncMetaStatus']);
    Route::apiResource('users', UserController::class);
});

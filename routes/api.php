<?php

use App\Http\Controllers\api\admin\AdminController;
use App\Http\Controllers\api\admin\DashboardController;
use App\Http\Controllers\api\admin\DiscountController;
use App\Http\Controllers\api\admin\MessengerAccountController;
use App\Http\Controllers\api\admin\OrderController;
use App\Http\Controllers\api\admin\PackageController;
use App\Http\Controllers\api\admin\SettingController;
use App\Http\Controllers\api\admin\TaxController;
use App\Http\Controllers\api\admin\UserController;
use App\Http\Controllers\api\auth\LoginController;
use App\Http\Controllers\api\HomeController;
use App\Http\Controllers\api\user\HomeController as UserHomeController;
use App\Http\Controllers\api\user\MessengerPagesController;
use App\Http\Controllers\api\user\WhatsPagesController;
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

// WhatsApp Webhook (public — Meta requires GET for verification + POST for messages)
Route::get('/web-hook', [HomeController::class, 'web_hook']);
Route::post('/web-hook', [HomeController::class, 'web_hook']);

// Messenger Webhook (public — Meta requires GET for verification + POST for messages)
Route::get('/messenger-webhook', [HomeController::class, 'messenger_web_hook']);
Route::post('/messenger-webhook', [HomeController::class, 'messenger_web_hook']);

Route::view('/privacy-policy', 'privacy-policy');

/*
|--------------------------------------------------------------------------
| Authentication Routes (Admin & User Login + Facebook OAuth)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('admin/login', [LoginController::class, 'adminLogin']);
    Route::post('user/login', [LoginController::class, 'userLogin']);
    Route::post('facebook', [LoginController::class, 'facebookLogin']);
    Route::post('logout', [LoginController::class, 'logout'])->middleware('auth:sanctum');
});

/*
|--------------------------------------------------------------------------
| User Routes (Protected by auth:sanctum & user middleware)
|--------------------------------------------------------------------------
*/
Route::prefix('user')->group(function () {
    // Public user routes
    Route::get('packages', [UserHomeController::class, 'packages']);
    Route::post('contact-us', [UserHomeController::class, 'contactUs'])
        ->middleware('throttle:contact-us');

    // Authenticated user routes
    Route::middleware(['auth:sanctum', 'user'])->group(function () {
        Route::get('dashboard', [UserHomeController::class, 'index']);

        // Messenger Self-Service — list pages & request subscription
        Route::get('messenger/facebook_packages', [MessengerPagesController::class, 'facebook_packages']);
        Route::get('messenger/pages', [MessengerPagesController::class, 'pages']);
        Route::post('messenger/orders', [MessengerPagesController::class, 'requestSubscription']);

        Route::get('whats/pages', [WhatsPagesController::class, 'pages']);
    });
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

    // Orders Management — list, create (WhatsApp), show, approve, reject
    Route::get('orders/lists', [OrderController::class, 'lists']);
    Route::get('orders/lookup', [OrderController::class, 'lists']);
    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::post('orders/{order}/approve', [OrderController::class, 'approve']);
    Route::post('orders/{order}/reject', [OrderController::class, 'reject']);

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

    // Messenger Pages Management (Admin manual control — kept for override capability)
    Route::post(
        'users/{user}/messenger-accounts/{messengerAccount}/regenerate-token',
        [MessengerAccountController::class, 'regenerateVerifyToken']
    );
    Route::apiResource('users/{user}/messenger-accounts', MessengerAccountController::class);
});

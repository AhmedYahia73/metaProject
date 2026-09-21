<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

// Quick shortcuts for documentation links
Route::redirect('docs/admin', '/docs/api/admin');
Route::redirect('docs/user', '/docs/api/user');
Route::redirect('docs/auth', '/docs/api/auth');

// Meta / WhatsApp App Compliance Pages
Route::view('/privacy-policy', 'privacy-policy')->name('privacy.policy');
Route::redirect('/privacy', '/privacy-policy');
Route::view('/data-deletion', 'privacy-policy')->name('data.deletion');

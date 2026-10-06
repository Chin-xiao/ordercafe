<?php

use App\Http\Controllers\Admin\OrderSessionController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\TelegramSettingsController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\MiniApp\OrderController;
use App\Http\Controllers\MiniApp\ProductController as MiniAppProductController;
use App\Http\Controllers\MiniApp\SessionController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Webhook Routes (Accessed by Telegram Servers)
|--------------------------------------------------------------------------
*/
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);
Route::post('/admin/register', [AuthController::class, 'register']);
Route::post('/admin/login', [AuthController::class, 'login']);
/*
|--------------------------------------------------------------------------
| Mini App Routes (Customer Facing via Telegram)
|--------------------------------------------------------------------------
*/
Route::prefix('mini-app')->middleware(['telegram.mini.app'])->group(function () {
    Route::get('/products', [MiniAppProductController::class, 'index']);
    Route::get('/categories', [MiniAppProductController::class, 'categories']);
    Route::get('/order-session/current', [SessionController::class, 'current']);

    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/my-order', [OrderController::class, 'myOrder']);
});

/*
|--------------------------------------------------------------------------
| Admin Panel Routes (Protected by Sanctum & Admin Middleware)
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // Order Sessions (supporting both naming conventions)
    Route::get('/order-sessions', [OrderSessionController::class, 'index']);
    Route::get('/sessions', [OrderSessionController::class, 'index']);

    Route::post('/order-sessions', [OrderSessionController::class, 'store']);
    Route::post('/sessions', [OrderSessionController::class, 'store']);

    Route::get('/order-sessions/{orderSession}', [OrderSessionController::class, 'show']);
    Route::get('/sessions/{orderSession}', [OrderSessionController::class, 'show']);

    Route::post('/order-sessions/{orderSession}/start', [OrderSessionController::class, 'start']);
    Route::post('/sessions/{orderSession}/start', [OrderSessionController::class, 'start']);

    Route::post('/order-sessions/{orderSession}/close', [OrderSessionController::class, 'close']);
    Route::post('/sessions/{orderSession}/close', [OrderSessionController::class, 'close']);

    // Admin Products & Categories Management
    Route::get('/products', [MiniAppProductController::class, 'index']);
    Route::get('/categories', [MiniAppProductController::class, 'categories']); // <--- Added this to fix the 404

    // Telegram Settings (Managed by logged-in admins)
    Route::get('/telegram-settings', [TelegramSettingsController::class, 'show']);
    Route::post('/telegram-settings/regenerate', [TelegramSettingsController::class, 'regenerate']);

    // Dashboard & Reports (Matching frontend paths explicitly)
    Route::get('/dashboard', [ReportController::class, 'dashboard']);
    Route::get('/reports/sales', [ReportController::class, 'sales']);
    Route::get('/reports/products', [ReportController::class, 'products']);

    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

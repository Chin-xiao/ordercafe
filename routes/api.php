<?php

use App\Http\Controllers\Admin\OrderSessionController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\MiniApp\OrderController;
use App\Http\Controllers\MiniApp\ProductController; // Use this for both!
use App\Http\Controllers\MiniApp\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('mini-app')->middleware(['telegram.mini.app'])->group(function () {
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/categories', [ProductController::class, 'categories']);
    Route::get('/order-session/current', [SessionController::class, 'current']);

    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/my-order', [OrderController::class, 'myOrder']);
});

Route::prefix('admin')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // Order Sessions
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

    // Reuse MiniApp ProductController for Admin products listing
    Route::get('/products', [ProductController::class, 'index']);

    // Dashboard & Reports
    Route::get('/dashboard', [ReportController::class, 'dashboard']);
    Route::get('/reports/sales', [ReportController::class, 'sales']);
    Route::get('/reports/products', [ReportController::class, 'products']);
});

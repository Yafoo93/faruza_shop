<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/activity-logs', [ActivityLogController::class, 'index']);
    Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'show', 'update']);
    Route::post('/customers/{customer}/payment', [CustomerController::class, 'payment']);
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/inventory/history', [InventoryController::class, 'history']);
    Route::get('/notifications/alerts', [NotificationController::class, 'alerts']);
    Route::get('/reports/sales', [ReportController::class, 'sales']);
    Route::get('/reports/sales/{sale}/receipt', [ReportController::class, 'receipt']);
    Route::get('/sales', [SaleController::class, 'index']);
    Route::get('/sales/{sale}', [SaleController::class, 'show']);
    Route::post('/sales', [SaleController::class, 'store']);
    Route::post('/sales/{sale}/refund', [SaleController::class, 'refund']);
    Route::post('/sales/{sale}/void', [SaleController::class, 'void']);

    Route::apiResource('products', ProductController::class);
    Route::post('/products/{id}/restore', [ProductController::class, 'restore']);
    Route::post('/products/{id}/restock', [ProductController::class, 'restock']);

    Route::apiResource('users', UserController::class)->only(['index', 'store', 'update']);
    Route::post('/users/{user}/disable', [UserController::class, 'disable']);
    Route::post('/users/{user}/enable', [UserController::class, 'enable']);
});

<?php

/*
 * API quản lý kho HR-GA. File được nạp từ cuối routes/api.php nên mọi đường dẫn
 * có dạng /api/kho/... và dùng chung middleware xác thực Workdesk.
 */

use App\Http\Controllers\KhoItemsController;
use App\Http\Controllers\KhoOrdersController;
use App\Http\Controllers\KhoOverviewController;
use App\Http\Controllers\KhoReportsController;
use App\Http\Controllers\KhoSettingsController;
use App\Http\Controllers\KhoTransactionsController;
use App\Http\Middleware\AuthenticateWorkdesk;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticateWorkdesk::class)->prefix('kho')->group(function (): void {
    Route::get('/me', [KhoOverviewController::class, 'me']);
    Route::get('/mine', [KhoOverviewController::class, 'mine']);
    Route::get('/dashboard', [KhoOverviewController::class, 'dashboard']);
    Route::get('/stock', [KhoOverviewController::class, 'stock']);
    Route::get('/schedule', [KhoOverviewController::class, 'schedule']);
    Route::get('/plan', [KhoOverviewController::class, 'plan']);
    Route::get('/logs', [KhoOverviewController::class, 'logs']);
    Route::post('/reminders', [KhoOverviewController::class, 'markReminders']);

    Route::get('/items', [KhoItemsController::class, 'index']);
    Route::post('/items', [KhoItemsController::class, 'store']);
    Route::post('/items/import', [KhoItemsController::class, 'import']);
    Route::patch('/items/{id}', [KhoItemsController::class, 'update']);
    Route::delete('/items/{id}', [KhoItemsController::class, 'destroy']);

    Route::get('/issues', [KhoTransactionsController::class, 'issues']);
    Route::post('/issues', [KhoTransactionsController::class, 'storeIssue']);
    Route::post('/issues/confirm', [KhoTransactionsController::class, 'confirmIssues']);
    Route::post('/issues/{id}/shortage', [KhoTransactionsController::class, 'markShortage']);
    Route::post('/issues/{id}/cancel', [KhoTransactionsController::class, 'cancelIssue']);
    Route::get('/receipts', [KhoTransactionsController::class, 'receipts']);
    Route::post('/receipts', [KhoTransactionsController::class, 'storeReceipt']);
    Route::post('/receipts/{id}/cancel', [KhoTransactionsController::class, 'cancelReceipt']);

    Route::get('/orders', [KhoOrdersController::class, 'index']);
    Route::post('/orders', [KhoOrdersController::class, 'store']);
    Route::post('/orders/from-plan', [KhoOrdersController::class, 'fromPlan']);
    Route::patch('/orders/{id}/status', [KhoOrdersController::class, 'updateStatus']);

    Route::get('/reports', [KhoReportsController::class, 'index']);

    Route::get('/settings', [KhoSettingsController::class, 'show']);
    Route::put('/settings', [KhoSettingsController::class, 'update']);
    Route::put('/staff/{userId}', [KhoSettingsController::class, 'updateStaff']);
    Route::get('/employees', [KhoSettingsController::class, 'employees']);
    Route::patch('/employees/{userId}/sizes', [KhoSettingsController::class, 'updateSizes']);
    Route::post('/employees/sizes/import', [KhoSettingsController::class, 'importSizes']);
});

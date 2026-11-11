<?php

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentsController;
use App\Http\Controllers\DepartmentsController;
use App\Http\Controllers\MeetingsController;
use App\Http\Controllers\MealReportsController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OvertimeController;
use App\Http\Controllers\PermitsController;
use App\Http\Controllers\SealProfilesController;
use App\Http\Middleware\AuthenticateWorkdesk;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::get('/auth/status', [AuthController::class, 'status']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout']);

Broadcast::routes(['middleware' => [AuthenticateWorkdesk::class]]);

Route::middleware(AuthenticateWorkdesk::class)->group(function (): void {
    Route::patch('/account/profile', [AccountsController::class, 'updateOwnProfile']);
    Route::post('/account/password', [AccountsController::class, 'changePassword']);

    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::get('/meal-reports', [MealReportsController::class, 'index']);
    Route::post('/meal-reports', [MealReportsController::class, 'store']);
    Route::put('/meal-reports/cutoffs', [MealReportsController::class, 'updateCutoffs']);
    Route::put('/meal-reports/departments/{departmentId}/reporters', [MealReportsController::class, 'updateReporters']);

    Route::get('/overtime/meta', [OvertimeController::class, 'meta']);
    Route::get('/overtime/mine', [OvertimeController::class, 'mine']);
    Route::get('/overtime/team', [OvertimeController::class, 'team']);
    Route::get('/overtime/approvals', [OvertimeController::class, 'approvals']);
    Route::post('/overtime/approvals/decision', [OvertimeController::class, 'bulkDecision']);
    Route::put('/overtime/lock', [OvertimeController::class, 'updateLock']);
    Route::post('/overtime', [OvertimeController::class, 'store']);
    Route::patch('/overtime/{id}', [OvertimeController::class, 'update'])->whereNumber('id');
    Route::delete('/overtime/{id}', [OvertimeController::class, 'destroy'])->whereNumber('id');
    Route::post('/overtime/{id}/decision', [OvertimeController::class, 'decision'])->whereNumber('id');

    Route::get('/admin/users', [AccountsController::class, 'listUsers']);
    Route::post('/admin/users', [AccountsController::class, 'createUser']);
    Route::patch('/admin/users/{id}/profile', [AccountsController::class, 'updateAdminProfile']);
    Route::patch('/admin/users/{id}/password', [AccountsController::class, 'updateAdminPassword']);
    Route::patch('/admin/users/{id}/access', [AccountsController::class, 'updateUserAccess']);

    Route::get('/organization', [OrganizationController::class, 'index']);
    Route::post('/organization/divisions', [OrganizationController::class, 'storeDivision']);
    Route::patch('/organization/divisions/{id}', [OrganizationController::class, 'updateDivision']);
    Route::delete('/organization/divisions/{id}', [OrganizationController::class, 'deleteDivision']);
    Route::post('/organization/divisions/{id}/manager', [OrganizationController::class, 'assignDivisionManager']);
    Route::post('/organization/departments/{id}/manager', [OrganizationController::class, 'assignDepartmentManager']);
    Route::post('/organization/teams', [OrganizationController::class, 'storeTeam']);
    Route::patch('/organization/teams/{id}', [OrganizationController::class, 'updateTeam']);
    Route::delete('/organization/teams/{id}', [OrganizationController::class, 'deleteTeam']);
    Route::post('/organization/teams/{id}/manager', [OrganizationController::class, 'assignTeamManager']);

    Route::get('/departments', [DepartmentsController::class, 'index']);
    Route::post('/departments', [DepartmentsController::class, 'store']);
    Route::patch('/departments/{id}', [DepartmentsController::class, 'update']);
    Route::delete('/departments/{id}', [DepartmentsController::class, 'destroy']);
    Route::get('/departments/{id}/users', [DepartmentsController::class, 'users']);
    Route::patch('/departments/{id}/users/{userId}', [DepartmentsController::class, 'assignUser']);
    Route::delete('/departments/{id}/users/{userId}', [DepartmentsController::class, 'removeUser']);

    Route::get('/meetings/users', [MeetingsController::class, 'users']);
    Route::get('/meetings/rooms', [MeetingsController::class, 'rooms']);
    Route::post('/meetings/rooms', [MeetingsController::class, 'createRoom']);
    Route::patch('/meetings/rooms/{id}', [MeetingsController::class, 'updateRoom']);
    Route::get('/meetings/availability', [MeetingsController::class, 'availability']);
    Route::get('/meetings', [MeetingsController::class, 'index']);
    Route::post('/meetings', [MeetingsController::class, 'store']);

    Route::get('/permits/users', [PermitsController::class, 'users']);
    Route::get('/permits', [PermitsController::class, 'index']);
    Route::post('/permits', [PermitsController::class, 'store']);
    Route::post('/permits/{id}/decision', [PermitsController::class, 'decision']);
    Route::post('/permits/{id}/guard', [PermitsController::class, 'guard']);

    // Đóng dấu PDF kiểu chuyển tay: người đang giữ tài liệu đặt/sửa dấu của mình rồi chuyển tiếp.
    Route::get('/documents/mine', [DocumentsController::class, 'mine']);
    Route::get('/documents/completed', [DocumentsController::class, 'completed']);
    Route::get('/documents/recipients', [DocumentsController::class, 'recipients']);
    Route::post('/documents', [DocumentsController::class, 'upload']);
    Route::get('/documents/{id}', [DocumentsController::class, 'show'])->whereNumber('id');
    Route::get('/documents/{id}/file', [DocumentsController::class, 'file']);
    Route::get('/documents/{id}/stamped', [DocumentsController::class, 'stamped']);
    Route::post('/documents/{id}/seals', [DocumentsController::class, 'addSeal']);
    Route::patch('/documents/{id}/seals/{sealId}', [DocumentsController::class, 'updateSeal']);
    Route::delete('/documents/{id}/seals/{sealId}', [DocumentsController::class, 'deleteSeal']);
    Route::get('/documents/{id}/seal-images/{sealId}', [DocumentsController::class, 'sealImage']);
    Route::post('/documents/{id}/forward', [DocumentsController::class, 'forward']);
    Route::post('/documents/{id}/complete', [DocumentsController::class, 'complete']);
    Route::get('/seal-profile', [SealProfilesController::class, 'show']);
    Route::post('/seal-profile', [SealProfilesController::class, 'update']);
    Route::get('/seal-profile/image', [SealProfilesController::class, 'image']);
    Route::patch('/profile', [DocumentsController::class, 'setSealProfile']);
});

require __DIR__.'/kho.php';

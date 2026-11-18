<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\LeaveBalanceController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\LeaveTypeController;
use App\Http\Controllers\Api\ShortLeaveController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
| JSON API for the same-origin SPA, versioned under /api/v1.
|
| Loaded in bootstrap/app.php inside the "web" (session, cookies, CSRF) and
| "tenant" middleware: every route here runs for a resolved, active tenant.
| Protected routes add "tenant.auth". Authorization: policies in app/Policies.
*/

Route::get('/tenant', [AuthController::class, 'tenant'])->name('tenant.show');

Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

Route::middleware('tenant.auth')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::apiResource('departments', DepartmentController::class);
    Route::apiResource('employees', EmployeeController::class);
    Route::apiResource('users', UserController::class)->only(['index', 'store', 'update']);

    Route::apiResource('leave-types', LeaveTypeController::class)->except(['show']);
    Route::get('/leave-balances', LeaveBalanceController::class)->name('leave-balances');
    Route::apiResource('leave-requests', LeaveRequestController::class)->only(['index', 'store', 'show']);
    Route::post('/leave-requests/{leave_request}/approve', [LeaveRequestController::class, 'approve'])->name('leave-requests.approve');
    Route::post('/leave-requests/{leave_request}/reject', [LeaveRequestController::class, 'reject'])->name('leave-requests.reject');
    Route::post('/leave-requests/{leave_request}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave-requests.cancel');

    // Hourly permissions.
    // Explicit name: Laravel would singularise "leaves" to {short_leaf}.
    Route::apiResource('short-leaves', ShortLeaveController::class)->only(['index', 'store', 'show'])
        ->parameters(['short-leaves' => 'short_leave']);
    Route::post('/short-leaves/{short_leave}/approve', [ShortLeaveController::class, 'approve'])->name('short-leaves.approve');
    Route::post('/short-leaves/{short_leave}/reject', [ShortLeaveController::class, 'reject'])->name('short-leaves.reject');
    Route::post('/short-leaves/{short_leave}/cancel', [ShortLeaveController::class, 'cancel'])->name('short-leaves.cancel');
});

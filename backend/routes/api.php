<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\LeaveBalanceController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\LeaveTypeController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SalaryController;
use App\Http\Controllers\Api\SettingsController;
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
Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgot'])->name('auth.password.forgot');
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->name('auth.password.reset');

Route::middleware('tenant.auth')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::apiResource('departments', DepartmentController::class);
    Route::apiResource('employees', EmployeeController::class);
    Route::apiResource('users', UserController::class)->only(['index', 'store', 'update']);

    Route::apiResource('holidays', HolidayController::class)->except(['show']);
    Route::apiResource('leave-types', LeaveTypeController::class)->except(['show']);
    Route::get('/leave-balances', LeaveBalanceController::class)->name('leave-balances');
    Route::apiResource('leave-requests', LeaveRequestController::class)->only(['index', 'store', 'show']);
    Route::post('/leave-requests/{leave_request}/approve', [LeaveRequestController::class, 'approve'])->name('leave-requests.approve');
    Route::post('/leave-requests/{leave_request}/reject', [LeaveRequestController::class, 'reject'])->name('leave-requests.reject');
    Route::post('/leave-requests/{leave_request}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave-requests.cancel');

    // Attendance (check-in / check-out with location).
    Route::get('/attendance/today', [AttendanceController::class, 'today'])->name('attendance.today');
    Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn'])->name('attendance.check-in');
    Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut'])->name('attendance.check-out');
    Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::post('/attendance', [AttendanceController::class, 'store'])->name('attendance.store');
    Route::put('/attendance/{attendance}', [AttendanceController::class, 'update'])->name('attendance.update');

    // Salary history.
    Route::get('/employees/{employee}/salaries', [SalaryController::class, 'index'])->name('salaries.index');
    Route::post('/employees/{employee}/salaries', [SalaryController::class, 'store'])->name('salaries.store');
    Route::delete('/salaries/{salary}', [SalaryController::class, 'destroy'])->name('salaries.destroy');

    // Company settings and reports.
    Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::get('/reports/{type}', ReportController::class)->name('reports.show');

    // Payroll (admins and HR) and the employee's own payslips.
    Route::get('/payroll-runs', [PayrollController::class, 'index'])->name('payroll.index');
    Route::post('/payroll-runs', [PayrollController::class, 'store'])->name('payroll.store');
    Route::get('/payroll-runs/{payrollRun}', [PayrollController::class, 'show'])->name('payroll.show');
    Route::post('/payroll-runs/{payrollRun}/recalculate', [PayrollController::class, 'recalculate'])->name('payroll.recalculate');
    Route::post('/payroll-runs/{payrollRun}/finalize', [PayrollController::class, 'finalize'])->name('payroll.finalize');
    Route::get('/payroll-runs/{payrollRun}/export', [PayrollController::class, 'export'])->name('payroll.export');
    Route::delete('/payroll-runs/{payrollRun}', [PayrollController::class, 'destroy'])->name('payroll.destroy');
    Route::put('/payslips/{payslip}', [PayrollController::class, 'updatePayslip'])->name('payslips.update');
    Route::get('/my/payslips', [PayrollController::class, 'mine'])->name('payslips.mine');

    // Employee documents.
    Route::get('/employees/{employee}/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('/employees/{employee}/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('/documents/expiring', [DocumentController::class, 'expiring'])->name('documents.expiring');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

    // Hourly permissions.
    // Explicit name: Laravel would singularise "leaves" to {short_leaf}.
    Route::apiResource('short-leaves', ShortLeaveController::class)->only(['index', 'store', 'show'])
        ->parameters(['short-leaves' => 'short_leave']);
    Route::post('/short-leaves/{short_leave}/approve', [ShortLeaveController::class, 'approve'])->name('short-leaves.approve');
    Route::post('/short-leaves/{short_leave}/reject', [ShortLeaveController::class, 'reject'])->name('short-leaves.reject');
    Route::post('/short-leaves/{short_leave}/cancel', [ShortLeaveController::class, 'cancel'])->name('short-leaves.cancel');
});

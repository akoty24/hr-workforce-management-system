<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DepartmentController;
use Illuminate\Support\Facades\Route;

/*
| JSON API for the same-origin SPA, versioned under /api/v1.
|
| Loaded in bootstrap/app.php inside the "web" (session, cookies, CSRF) and
| "tenant" middleware: every route here runs for a resolved, active tenant.
| Protected routes add "tenant.auth".
*/

Route::get('/tenant', [AuthController::class, 'tenant'])->name('tenant.show');

Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

Route::middleware('tenant.auth')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');

    Route::apiResource('departments', DepartmentController::class);
});

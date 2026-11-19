<?php

use App\Http\Controllers\Platform\PlatformController;
use Illuminate\Support\Facades\Route;

/*
| Owner console API under /api/platform, only on the platform host
| (see EnsurePlatformHost). Runs in the "web" group, never with a tenant.
*/

Route::post('/login', [PlatformController::class, 'login'])->name('login');
Route::post('/logout', [PlatformController::class, 'logout'])->name('logout');
Route::get('/me', [PlatformController::class, 'me'])->name('me');
Route::put('/password', [PlatformController::class, 'password'])->name('password');

Route::get('/tenants', [PlatformController::class, 'tenants'])->name('tenants.index');
Route::post('/tenants', [PlatformController::class, 'store'])->name('tenants.store');
Route::patch('/tenants/{tenant}', [PlatformController::class, 'update'])->name('tenants.update');
Route::get('/tenants/{tenant}/admins', [PlatformController::class, 'admins'])->name('tenants.admins');
Route::put('/tenants/{tenant}/admins/{user}/password', [PlatformController::class, 'resetAdminPassword'])
    ->whereUuid('user')
    ->name('tenants.admins.password');

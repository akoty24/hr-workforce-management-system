<?php

use App\Http\Controllers\Health\ReadinessController;
use Illuminate\Support\Facades\Route;

/*
| Health probes. Registered outside the "web" middleware group on purpose:
| no session, cookies or CSRF, so frequent probes never write session rows.
|
|   GET /up            liveness  — process is running (Laravel built-in)
|   GET /health/ready  readiness — dependencies (MySQL) reachable
*/

Route::get('/health/ready', ReadinessController::class)->name('health.ready');

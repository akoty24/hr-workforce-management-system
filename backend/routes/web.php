<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// Every page path not matched by an API/health route is the React SPA.
Route::fallback(SpaController::class);

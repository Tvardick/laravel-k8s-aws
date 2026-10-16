<?php

use App\Http\Controllers\ProbeController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [ProbeController::class, 'health']);
Route::get('/ready', [ProbeController::class, 'ready']);

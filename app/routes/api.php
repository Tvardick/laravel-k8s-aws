<?php

use App\Http\Controllers\NoteController;
use Illuminate\Support\Facades\Route;

// Numeric ids only: on PostgreSQL a non-numeric id would fail in SQL with 500 instead of 404.
Route::apiResource('notes', NoteController::class)->whereNumber('note');

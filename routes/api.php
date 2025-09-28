<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you may register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group and the "api" prefix.
|
*/

// Public API route (no authentication required)
Route::get('/public-data', function() {
    return response()->json([
        'data' => 'This is public data accessible without authentication.'
    ]);
});

// Sanctum-protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Return the authenticated user details
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});

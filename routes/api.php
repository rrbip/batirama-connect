<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // Health check
    Route::get('/health', fn () => response()->json(['status' => 'ok']));

    // Partners API (authenticated via API key)
    Route::prefix('partners')->group(function () {
        // TODO: Add partner authentication middleware
        Route::post('/sessions', fn () => response()->json(['message' => 'TODO']));
        Route::get('/sessions/{id}', fn () => response()->json(['message' => 'TODO']));
        Route::post('/conversions', fn () => response()->json(['message' => 'TODO']));
    });

    // Public tokens
    Route::prefix('public-tokens')->group(function () {
        Route::post('/', fn () => response()->json(['message' => 'TODO']));
    });
});

<?php

declare(strict_types=1);

use App\Http\Controllers\Api\PartnerApiController;
use App\Http\Controllers\Api\PublicChatController;
use App\Http\Middleware\PartnerApiAuth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health check (no auth required)
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'timestamp' => now()->toIso8601String(),
]));

// API v1
Route::prefix('v1')->group(function () {

    // Partner API (authenticated via API key)
    Route::prefix('partners')
        ->middleware(PartnerApiAuth::class)
        ->group(function () {
            // Sessions
            Route::post('/sessions', [PartnerApiController::class, 'createSession']);
            Route::get('/sessions/{session_id}', [PartnerApiController::class, 'getSession']);

            // Conversions
            Route::post('/conversions', [PartnerApiController::class, 'notifyConversion']);
        });

});

// Public chat endpoints (no auth, token-based access)
Route::prefix('c')->group(function () {
    Route::get('/{token}', [PublicChatController::class, 'show']);
    Route::post('/{token}/start', [PublicChatController::class, 'start']);
    Route::post('/{token}/message', [PublicChatController::class, 'sendMessage']);
    Route::post('/{token}/end', [PublicChatController::class, 'end']);
    Route::get('/{token}/history', [PublicChatController::class, 'history']);
    Route::post('/{token}/upload', [PublicChatController::class, 'upload']);
});

// Message status endpoints (polling for async messages)
Route::prefix('messages')->group(function () {
    Route::get('/{uuid}/status', [PublicChatController::class, 'messageStatus']);
    Route::post('/{uuid}/retry', [PublicChatController::class, 'retryMessage']);
});

// Legacy support (redirect old endpoints)
Route::prefix('api/partners')->middleware(PartnerApiAuth::class)->group(function () {
    Route::post('/sessions', [PartnerApiController::class, 'createSession']);
    Route::get('/sessions/{session_id}', [PartnerApiController::class, 'getSession']);
    Route::post('/conversions', [PartnerApiController::class, 'notifyConversion']);
});

<?php

declare(strict_types=1);

use App\Http\Controllers\Api\PartnerApiController;
use App\Http\Controllers\Api\PublicChatController;
use App\Http\Controllers\Api\Whitelabel\EditorController;
use App\Http\Controllers\Api\Whitelabel\MarketplaceController;
use App\Http\Controllers\Api\Whitelabel\WidgetController;
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

/*
|--------------------------------------------------------------------------
| Whitelabel API Routes
|--------------------------------------------------------------------------
|
| Routes pour l'API whitelabel (éditeurs tiers comme EBP, SAGE, etc.)
|
*/

// Widget API (authentifié via deployment_key)
Route::prefix('whitelabel')
    ->middleware(['deployment.key', 'deployment.domain', 'deployment.rate', 'deployment.cors'])
    ->group(function () {
        // Configuration du déploiement
        Route::get('/config', [WidgetController::class, 'getConfig']);

        // Sessions
        Route::post('/sessions', [WidgetController::class, 'init'])
            ->middleware('editor.quota:session');
        Route::get('/sessions/{sessionId}', [WidgetController::class, 'getSession']);
        Route::delete('/sessions/{sessionId}', [WidgetController::class, 'closeSession']);
        Route::get('/sessions/{sessionId}/messages', [WidgetController::class, 'getMessages']);

        // Messages
        Route::post('/sessions/{sessionId}/messages', [WidgetController::class, 'sendMessage'])
            ->middleware('editor.quota:message');

        // Files (upload and list)
        Route::post('/sessions/{sessionId}/upload', [WidgetController::class, 'uploadFile']);
        Route::get('/sessions/{sessionId}/files', [WidgetController::class, 'getFiles']);
    });

// Editor API (authentifié via API key éditeur)
Route::prefix('editor')
    ->middleware('editor.auth')
    ->group(function () {
        // Déploiements
        Route::get('/deployments', [EditorController::class, 'listDeployments']);
        Route::post('/deployments', [EditorController::class, 'createDeployment']);
        Route::put('/deployments/{deploymentId}', [EditorController::class, 'updateDeployment']);

        // Artisans
        Route::get('/artisans', [EditorController::class, 'listArtisans']);
        Route::post('/artisans/link', [EditorController::class, 'linkArtisan']);
        Route::post('/artisans/create-and-link', [EditorController::class, 'createAndLinkArtisan']);

        // Sessions
        Route::post('/sessions/create-link', [EditorController::class, 'createSessionLink']);

        // Stats
        Route::get('/stats', [EditorController::class, 'getStats']);

        // Marketplace
        Route::prefix('marketplace')->group(function () {
            // Notification de devis signé
            Route::post('/quote-signed', [MarketplaceController::class, 'quoteSigned']);

            // Gestion des commandes
            Route::get('/orders', [MarketplaceController::class, 'listOrders']);
            Route::get('/orders/{orderId}', [MarketplaceController::class, 'getOrder']);
            Route::post('/orders/{orderId}/validate', [MarketplaceController::class, 'validateOrder']);
            Route::post('/orders/{orderId}/cancel', [MarketplaceController::class, 'cancelOrder']);

            // Gestion des items
            Route::patch('/orders/{orderId}/items/{itemId}', [MarketplaceController::class, 'updateOrderItem']);
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
    Route::post('/{token}/email', [PublicChatController::class, 'saveEmail']);
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

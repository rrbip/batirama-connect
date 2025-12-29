<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Whitelabel\StandaloneChatController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'AI-Manager CMS',
        'version' => '1.0.0',
        'status' => 'running',
        'api' => [
            'health' => '/api/health',
            'documentation' => '/api/docs',
        ],
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Standalone chat pages (whitelabel session links)
Route::get('/s/{token}', [StandaloneChatController::class, 'show'])
    ->name('whitelabel.standalone');

// Public chat access (legacy token)
Route::get('/c/{token}', [StandaloneChatController::class, 'show'])
    ->name('public.chat');

// Admin routes for document management
Route::middleware(['web', 'auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
        ->name('documents.download');
    Route::get('/documents/{document}/view', [DocumentController::class, 'view'])
        ->name('documents.view');
});

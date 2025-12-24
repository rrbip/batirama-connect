<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DocumentController;
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

// Public chat access (via token)
Route::get('/c/{token}', function (string $token) {
    // TODO: Implement public chat access
    return "Chat public - Token: {$token}";
})->name('public.chat');

// Admin routes for document management
Route::middleware(['web', 'auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
        ->name('documents.download');
    Route::get('/documents/{document}/view', [DocumentController::class, 'view'])
        ->name('documents.view');
});

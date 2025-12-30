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

// Test documents for pipeline testing (public access)
Route::get('/test-docs/{filename}', function (string $filename) {
    $path = storage_path("app/test-documents/{$filename}");

    if (!file_exists($path)) {
        abort(404, 'Document de test non trouvé');
    }

    $mimeType = mime_content_type($path) ?: 'text/html';

    return response()->file($path, [
        'Content-Type' => $mimeType,
    ]);
})->where('filename', '[a-zA-Z0-9_\-\.]+')->name('test-docs');

// Admin routes for document management
Route::middleware(['web', 'auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
        ->name('documents.download');
    Route::get('/documents/{document}/view', [DocumentController::class, 'view'])
        ->name('documents.view');
});

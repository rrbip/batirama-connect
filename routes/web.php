<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

// Public chat access (via token)
Route::get('/c/{token}', function (string $token) {
    // TODO: Implement public chat access
    return "Chat public - Token: {$token}";
})->name('public.chat');

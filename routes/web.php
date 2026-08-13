<?php

use App\Http\Controllers\Integrations\GoogleOAuthController;
use App\Http\Controllers\Integrations\MetaOAuthController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check()
        ? redirect('/app')
        : redirect('/system/login');
});

Route::redirect('/app/login', '/system/login');

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/integrations/google/callback', [GoogleOAuthController::class, 'callback'])
        ->name('integrations.google.callback');

    Route::get('/integrations/google/{integration}/authorize', [GoogleOAuthController::class, 'authorize'])
        ->name('integrations.google.authorize');

    Route::get('/integrations/meta/callback', [MetaOAuthController::class, 'callback'])
        ->name('integrations.meta.callback');

    Route::get('/integrations/meta/{integration}/authorize', [MetaOAuthController::class, 'authorize'])
        ->name('integrations.meta.authorize');
});

require __DIR__.'/demo.php';

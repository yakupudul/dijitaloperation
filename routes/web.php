<?php

use App\Http\Controllers\Integrations\GoogleOAuthController;
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
});

require __DIR__.'/demo.php';

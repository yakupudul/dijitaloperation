<?php

use App\Http\Controllers\Integrations\GoogleOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/integrations/google/callback', [GoogleOAuthController::class, 'callback'])
        ->name('integrations.google.callback');

    Route::get('/integrations/google/{integration}/authorize', [GoogleOAuthController::class, 'authorize'])
        ->name('integrations.google.authorize');
});

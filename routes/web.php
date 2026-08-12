<?php

use App\Http\Controllers\Integrations\GoogleOAuthController;
use App\Livewire\Operator\BrandsIndex;
use App\Livewire\Operator\CustomersIndex;
use App\Livewire\Operator\Dashboard;
use App\Livewire\Operator\DigitalAssetsIndex;
use App\Livewire\Operator\Meta\CampaignDetailPage;
use App\Livewire\Operator\Meta\CampaignsPage;
use App\Livewire\Operator\Meta\IntegrationPage;
use App\Livewire\Operator\Meta\OverviewPage;
use App\Support\Permissions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Root routes authenticated operators to the TailAdmin dashboard, guests to the
// Filament back-office login (the single credential entry point).
Route::get('/', function () {
    return Auth::check() ? redirect('/app') : redirect('/admin/login');
})->name('home');

// Legacy /app/login entry point now lives on the Filament back-office at /admin/login.
Route::get('/app/login', fn () => redirect('/admin/login'))->name('operator.login');

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/integrations/google/callback', [GoogleOAuthController::class, 'callback'])
        ->name('integrations.google.callback');

    Route::get('/integrations/google/{integration}/authorize', [GoogleOAuthController::class, 'authorize'])
        ->name('integrations.google.authorize');
});

// TailAdmin operator app (Blade/Livewire). Filament back-office deep CRUD stays at /admin.
Route::middleware(['web', 'auth', 'can:'.Permissions::ACCESS_APP])
    ->prefix('app')
    ->name('operator.')
    ->group(function (): void {
        // Livewire v4 (Filament v5) requires full-page components to be registered via
        // Route::livewire(); the v3-era Route::get(..., Component::class) form throws
        // "Invalid route action" at boot.
        Route::livewire('/', Dashboard::class)->name('dashboard');

        Route::livewire('/customers', CustomersIndex::class)->name('customers');
        Route::livewire('/brands', BrandsIndex::class)->name('brands');
        Route::livewire('/digital-assets', DigitalAssetsIndex::class)->name('digital-assets');

        Route::livewire('/meta', IntegrationPage::class)->name('meta');
        Route::livewire('/meta/assets/{digitalAsset}', OverviewPage::class)->name('meta.overview');
        Route::livewire('/meta/assets/{digitalAsset}/campaigns', CampaignsPage::class)->name('meta.campaigns');
        Route::livewire('/meta/assets/{digitalAsset}/campaigns/{campaignId}', CampaignDetailPage::class)->name('meta.campaign');
    });

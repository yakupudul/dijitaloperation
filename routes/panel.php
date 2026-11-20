<?php

use App\Livewire\Panel\HomePage;
use App\Livewire\Panel\LeadsPage;
use App\Livewire\Panel\PersonPage;
use App\Livewire\Panel\SoonPage;
use App\Livewire\Panel\UsersPage;
use App\Panel\Http\Controllers\PanelAuthController;
use App\Panel\Http\Controllers\PanelDownloadController;
use App\Panel\Http\Middleware\PanelAuthenticate;
use Illuminate\Support\Facades\Route;

// Müşteri paneli: these routes answer only on the panel host (config panel.domain); PanelHostBoundary refuses every
// other route there. Loaded inside the web group, before the operator routes.
Route::domain((string) config('panel.domain'))->name('panel.')->group(function (): void {
    Route::get('/giris', [PanelAuthController::class, 'create'])->name('login');
    Route::post('/giris', [PanelAuthController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
    Route::post('/cikis', [PanelAuthController::class, 'destroy'])->name('logout');
    Route::get('/gir/{membership}/{operator}', [PanelAuthController::class, 'viewAs'])->whereNumber(['membership', 'operator'])->name('view-as');

    Route::middleware(PanelAuthenticate::class)->group(function (): void {
        Route::get('/sifre', [PanelAuthController::class, 'password'])->name('password');
        Route::post('/sifre', [PanelAuthController::class, 'updatePassword'])->name('password.store');
        Route::post('/marka', [PanelAuthController::class, 'brand'])->name('brand');
        Route::get('/dosya/{file}', [PanelDownloadController::class, 'file'])->whereNumber('file')->middleware(PanelAuthenticate::class.':files.view')->name('file');
        Route::get('/disa-aktar/adaylar', [PanelDownloadController::class, 'export'])->middleware(PanelAuthenticate::class.':manager')->name('export.leads');

        Route::livewire('/', HomePage::class)->name('home');
        Route::livewire('/adaylar', LeadsPage::class)->name('leads');
        Route::livewire('/adaylar/{person}', PersonPage::class)->whereNumber('person')->name('person');
        Route::livewire('/gelen-kutusu', SoonPage::class)->name('inbox');
        Route::livewire('/performans', SoonPage::class)->name('performance');
        Route::livewire('/destek', SoonPage::class)->name('support');
        Route::livewire('/kullanicilar', UsersPage::class)->name('users');
    });
});

<?php

use App\Http\Controllers\Auth\OperatorForgotPasswordController;
use App\Http\Controllers\Auth\OperatorLoginController;
use App\Http\Controllers\Auth\OperatorResetPasswordController;
use App\Http\Controllers\Auth\OperatorTwoFactorChallengeController;
use App\Http\Controllers\Integrations\GoogleOAuthController;
use App\Http\Controllers\Integrations\MetaOAuthController;
use App\Http\Controllers\Integrations\WhatsAppBackupController;
use App\Http\Controllers\Integrations\WhatsAppExportController;
use App\Http\Controllers\Integrations\WhatsAppSignupController;
use App\Http\Controllers\LegacyRetiredPrefixController;
use App\Http\Controllers\Operator\PushController;
use App\Http\Controllers\Operator\WebsiteHtmlSnapshotController;
use App\Http\Controllers\Ops\OpsHealthController;
use App\Http\Middleware\EnsureDemoAppAccess;
use App\Livewire\Operator\AssetDataSourcesPage;
use App\Livewire\Operator\Integrations\WebsiteIntegrationIndex;
use App\Livewire\Operator\PublicDiscoveryIndex;
use App\Livewire\Operator\Website\PublicDiscoveryPage;
use App\Livewire\Operator\WhatsApp\Inbox as WhatsAppInbox;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [OperatorLoginController::class, 'create'])->name('app.login');
    Route::post('/login', [OperatorLoginController::class, 'store'])->middleware('throttle:10,1')->name('app.login.store');
    Route::get('/two-factor-challenge', [OperatorTwoFactorChallengeController::class, 'create'])->name('app.login.two-factor');
    Route::post('/two-factor-challenge', [OperatorTwoFactorChallengeController::class, 'store'])->middleware('throttle:5,1')->name('app.login.two-factor.store');
    Route::get('/forgot-password', [OperatorForgotPasswordController::class, 'create'])->name('app.password.request');
    Route::post('/forgot-password', [OperatorForgotPasswordController::class, 'store'])->name('app.password.email');
    Route::get('/reset-password/{token}', [OperatorResetPasswordController::class, 'create'])->name('app.password.reset');
    Route::post('/reset-password', [OperatorResetPasswordController::class, 'store'])->name('app.password.update');
});

Route::post('/logout', [OperatorLoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('app.logout');

Route::get('/up/liveness', [OpsHealthController::class, 'liveness'])->name('ops.liveness');
Route::get('/up/readiness', [OpsHealthController::class, 'readiness'])->name('ops.readiness');
Route::middleware(['web', 'auth'])->prefix('push')->name('push.')->group(function (): void {
    Route::get('/key', [PushController::class, 'key'])->name('key');
    Route::post('/subscribe', [PushController::class, 'subscribe'])->middleware('throttle:20,1')->name('subscribe');
    Route::post('/unsubscribe', [PushController::class, 'unsubscribe'])->name('unsubscribe');
    Route::get('/latest', [PushController::class, 'latest'])->name('latest');
    Route::post('/test', [PushController::class, 'test'])->middleware('throttle:5,1')->name('test');
});
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/integrations/google/callback', [GoogleOAuthController::class, 'callback'])
        ->name('integrations.google.callback');

    Route::get('/integrations/google/{integration}/authorize', [GoogleOAuthController::class, 'authorize'])
        ->name('integrations.google.authorize');

    Route::get('/integrations/meta/callback', [MetaOAuthController::class, 'callback'])
        ->name('integrations.meta.callback');

    Route::get('/integrations/meta/{integration}/authorize', [MetaOAuthController::class, 'authorize'])
        ->name('integrations.meta.authorize');

    Route::get('/ops/health-snapshot', [OpsHealthController::class, 'snapshot'])
        ->name('ops.health.snapshot');
});

// Register the concrete Website integration route before demo.php's /integrations/{provider} catch-all.
Route::middleware(['web', 'auth', EnsureDemoAppAccess::class])->group(function (): void {
    Route::livewire('/integrations/website/{assetId?}', WebsiteIntegrationIndex::class)
        ->where('assetId', '[0-9]{1,18}')
        ->name('operator.integrations.website');
});

require __DIR__.'/demo.php';

// Canonical production operator engine surfaces that are intentionally kept outside legacy demo.php.
Route::middleware(['web', 'auth', EnsureDemoAppAccess::class])->group(function (): void {
    Route::livewire('/public-discovery', PublicDiscoveryIndex::class)
        ->name('operator.public-discovery');

    // Canonical data-source management for every bindable Digital Asset type.
    Route::livewire('/assets/{assetId}/sources', AssetDataSourcesPage::class)
        ->where('assetId', '[0-9]{1,18}')
        ->name('operator.asset.sources');

    // Backward-compatible Website URL; same canonical component.
    Route::livewire('/assets/website/{assetId}/sources', AssetDataSourcesPage::class)
        ->where('assetId', '[0-9]{1,18}')
        ->name('operator.website.sources');

    Route::livewire('/assets/website/{assetId}/discovery', PublicDiscoveryPage::class)
        ->where('assetId', '[0-9]{1,18}')
        ->name('operator.website.discovery');

    Route::get('/assets/website/{assetId}/html/{rawObjectId}', [WebsiteHtmlSnapshotController::class, 'show'])
        ->where(['assetId' => '[0-9]{1,18}', 'rawObjectId' => '[0-9]{1,18}'])
        ->name('operator.website.html.show');

    // WhatsApp inbox (admins): read conversations, AI reply suggestions to copy; MoxDOP never sends.
    Route::get('/whatsapp/connect/{attempt}', [WhatsAppSignupController::class, 'show'])
        ->whereUuid('attempt')->name('operator.whatsapp.connect');
    Route::post('/whatsapp/connect/{attempt}', [WhatsAppSignupController::class, 'complete'])
        ->whereUuid('attempt')->middleware('throttle:10,1,wa-complete')->name('operator.whatsapp.complete');
    Route::post('/whatsapp/connect/{attempt}/phone', [WhatsAppSignupController::class, 'selectPhone'])
        ->whereUuid('attempt')->middleware('throttle:10,1,wa-phone')->name('operator.whatsapp.select-phone');
    Route::post('/whatsapp/connect/{attempt}/report', [WhatsAppSignupController::class, 'report'])
        ->whereUuid('attempt')->middleware('throttle:30,1,wa-report')->name('operator.whatsapp.report');
    Route::post('/whatsapp/backup', [WhatsAppBackupController::class, 'begin'])
        ->middleware('throttle:10,1,wa-backup')->name('operator.whatsapp.backup');
    Route::post('/whatsapp/backup/{import}/chunk', [WhatsAppBackupController::class, 'chunk'])
        ->whereUuid('import')->middleware('throttle:600,1,wa-backup-chunk')->name('operator.whatsapp.backup.chunk');
    Route::get('/whatsapp/export', WhatsAppExportController::class)
        ->middleware('throttle:10,1,wa-export')->name('operator.whatsapp.export');
    Route::livewire('/whatsapp', WhatsAppInbox::class)
        ->name('operator.whatsapp');
});

Route::any('/app/{path?}', [LegacyRetiredPrefixController::class, 'app'])
    ->where('path', '.*');

Route::any('/system/{path?}', [LegacyRetiredPrefixController::class, 'system'])
    ->where('path', '.*');

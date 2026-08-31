<?php

use App\Http\Controllers\Auth\OperatorForgotPasswordController;
use App\Http\Controllers\Auth\OperatorLoginController;
use App\Http\Controllers\Auth\OperatorResetPasswordController;
use App\Http\Controllers\Integrations\GoogleOAuthController;
use App\Http\Controllers\Integrations\MetaOAuthController;
use App\Http\Controllers\LegacyRetiredPrefixController;
use App\Http\Controllers\Ops\OpsHealthController;
use App\Http\Controllers\Operator\WebsiteHtmlSnapshotController;
use App\Http\Controllers\Prospects\ProspectReportShareController;
use App\Http\Controllers\Reports\ReportArtifactDownloadController;
use App\Http\Controllers\Reports\ReportShareController;
use App\Http\Middleware\EnsureDemoAppAccess;
use App\Livewire\Operator\AssetDataSourcesPage;
use App\Livewire\Operator\Integrations\WebsiteIntegrationIndex;
use App\Livewire\Operator\PublicDiscoveryIndex;
use App\Livewire\Operator\Website\PublicDiscoveryPage;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [OperatorLoginController::class, 'create'])->name('app.login');
    Route::post('/login', [OperatorLoginController::class, 'store'])->name('app.login.store');
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

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/integrations/google/callback', [GoogleOAuthController::class, 'callback'])
        ->name('integrations.google.callback');

    Route::get('/integrations/google/{integration}/authorize', [GoogleOAuthController::class, 'authorize'])
        ->name('integrations.google.authorize');

    Route::get('/integrations/meta/callback', [MetaOAuthController::class, 'callback'])
        ->name('integrations.meta.callback');

    Route::get('/integrations/meta/{integration}/authorize', [MetaOAuthController::class, 'authorize'])
        ->name('integrations.meta.authorize');

    Route::get('/reports/artifacts/{artifactId}/download', [ReportArtifactDownloadController::class, 'download'])
        ->whereNumber('artifactId')
        ->name('reports.artifacts.download');

    Route::post('/reports/snapshots/{snapshotId}/pdf', [ReportArtifactDownloadController::class, 'generateAndDownload'])
        ->whereNumber('snapshotId')
        ->name('reports.snapshots.pdf');

    Route::get('/ops/health-snapshot', [OpsHealthController::class, 'snapshot'])
        ->name('ops.health.snapshot');
});

Route::middleware(['web'])->prefix('prospect-reports/share')->name('prospect-reports.share.')->group(function (): void {
    Route::get('/{token}/pdf', [ProspectReportShareController::class, 'pdf'])
        ->where('token', '[A-Za-z0-9\-_]+')
        ->name('pdf');
    Route::get('/{token}', [ProspectReportShareController::class, 'locator'])
        ->where('token', '[A-Za-z0-9\-_]+')
        ->name('locator');
});

Route::middleware(['web'])->prefix('reports/share')->name('reports.share.')->group(function (): void {
    Route::get('/access/verify', [ReportShareController::class, 'verifyForm'])
        ->name('verify.form');
    Route::post('/access/verify/request', [ReportShareController::class, 'requestCode'])
        ->name('verify.request');
    Route::post('/access/verify', [ReportShareController::class, 'verify'])
        ->name('verify.submit');
    Route::get('/access/view', [ReportShareController::class, 'view'])
        ->name('view');
    Route::get('/access/pdf', [ReportShareController::class, 'downloadPdf'])
        ->name('pdf');
    Route::get('/{token}', [ReportShareController::class, 'locator'])
        ->where('token', '[A-Za-z0-9\-_]+')
        ->name('locator');
});

// Register the concrete Website integration route before demo.php's /integrations/{provider} catch-all.
Route::middleware(['web', 'auth', EnsureDemoAppAccess::class])->group(function (): void {
    Route::livewire('/integrations/website/{assetId?}', WebsiteIntegrationIndex::class)
        ->whereNumber('assetId')
        ->name('operator.integrations.website');
});

require __DIR__.'/demo.php';

// Canonical production operator engine surfaces that are intentionally kept outside legacy demo.php.
Route::middleware(['web', 'auth', EnsureDemoAppAccess::class])->group(function (): void {
    Route::livewire('/public-discovery', PublicDiscoveryIndex::class)
        ->name('operator.public-discovery');

    // Canonical data-source management for every bindable Digital Asset type.
    Route::livewire('/assets/{assetId}/sources', AssetDataSourcesPage::class)
        ->whereNumber('assetId')
        ->name('operator.asset.sources');

    // Backward-compatible Website URL; same canonical component.
    Route::livewire('/assets/website/{assetId}/sources', AssetDataSourcesPage::class)
        ->whereNumber('assetId')
        ->name('operator.website.sources');

    Route::livewire('/assets/website/{assetId}/discovery', PublicDiscoveryPage::class)
        ->whereNumber('assetId')
        ->name('operator.website.discovery');

    Route::get('/assets/website/{assetId}/html/{rawObjectId}', [WebsiteHtmlSnapshotController::class, 'show'])
        ->whereNumber(['assetId', 'rawObjectId'])
        ->name('operator.website.html.show');
});

Route::any('/app/{path?}', [LegacyRetiredPrefixController::class, 'app'])
    ->where('path', '.*');

Route::any('/system/{path?}', [LegacyRetiredPrefixController::class, 'system'])
    ->where('path', '.*');

<?php

use App\Http\Controllers\Auth\OperatorLoginController;
use App\Http\Controllers\Integrations\GoogleOAuthController;
use App\Http\Controllers\Integrations\MetaOAuthController;
use App\Http\Controllers\Ops\OpsHealthController;
use App\Http\Controllers\Prospects\ProspectReportShareController;
use App\Http\Controllers\Reports\ReportArtifactDownloadController;
use App\Http\Controllers\Reports\ReportShareController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return Auth::check()
        ? redirect('/app')
        : redirect()->route('app.login');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/app/login', [OperatorLoginController::class, 'create'])->name('app.login');
    Route::post('/app/login', [OperatorLoginController::class, 'store'])->name('app.login.store');
});

Route::post('/app/logout', [OperatorLoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('app.logout');

// Legacy Filament operator path (/system) — retired. Keep narrow bookmark redirects only.
Route::redirect('/system/login', '/app/login');
Route::redirect('/system', '/app');
Route::any('/system/{path}', function (): never {
    abort(410, 'Legacy /system operator surface retired. Use /app.');
})->where('path', '.*');

// Prompt 66 — cheap health endpoints (no tenant/provider secrets).
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

    // Internal ops snapshot — authenticated operators only; no new top-level nav.
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

require __DIR__.'/demo.php';

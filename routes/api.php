<?php

use App\Http\Controllers\Calendar\QuickCaptureController;
use App\Http\Controllers\Integrations\WhatsAppWebhookController;
use App\Http\Controllers\Integrations\WordPressConnectorCommandsController;
use App\Http\Controllers\Integrations\WordPressConnectorEventsController;
use App\Http\Controllers\Integrations\WordPressConnectorPairController;
use App\Http\Controllers\Integrations\WordPressConnectorReleaseController;
use App\Http\Controllers\Integrations\WordPressThemeReleaseController;
use App\Http\Controllers\Sales\LeadFormController;
use App\Http\Controllers\Sales\PhoneBackupController;
use Illuminate\Support\Facades\Route;

Route::post('/connectors/wordpress/pair', WordPressConnectorPairController::class)
    ->middleware('throttle:10,1')
    ->name('api.connectors.wordpress.pair');

Route::post('/connectors/wordpress/events', WordPressConnectorEventsController::class)
    ->middleware('throttle:120,1')
    ->name('api.connectors.wordpress.events');

// 1.13.0: a site whose host refuses MoxDOP's requests fetches them here itself and returns the answers.
Route::post('/connectors/wordpress/commands', WordPressConnectorCommandsController::class)
    ->middleware('throttle:240,1')
    ->name('api.connectors.wordpress.commands');

// 1.4.1: short-lived signed link to one built connector ZIP, fetched by the plugin during an approved self-update.
Route::get('/connectors/wordpress/releases/{file}', WordPressConnectorReleaseController::class)
    ->middleware(['signed', 'throttle:30,1'])
    ->where('file', 'moxdop-wordpress-connector-[0-9.]+-[a-f0-9]{16}\.zip')
    ->name('api.connectors.wordpress.release');

// 1.33.0: short-lived signed link to one theme ZIP built from this repository, fetched during a site build.
Route::get('/connectors/wordpress/themes/{file}', WordPressThemeReleaseController::class)
    ->middleware(['signed', 'throttle:30,1'])
    ->where('file', '[a-z0-9][a-z0-9-]{1,40}-[a-f0-9]{16}\.zip')
    ->name('api.connectors.wordpress.theme');

Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify'])
    ->middleware('throttle:30,1')->name('api.whatsapp.webhook');
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'receive'])
    ->middleware('throttle:300,1')->name('api.whatsapp.receive');

// Telefon bağlantısı (2026-11-04): a tiny WebDAV folder the phone (FolderSync) puts its WhatsApp backup on every morning.
Route::match(['OPTIONS', 'GET', 'HEAD', 'PUT', 'PROPFIND', 'PROPPATCH', 'MKCOL', 'DELETE', 'MOVE', 'COPY', 'LOCK', 'UNLOCK'], '/telefon-yedek/{path?}', PhoneBackupController::class)
    ->where('path', '.*')->middleware('throttle:120,1,phone-backup')->name('api.phone-backup');

// Satış (2026-11-05): site forms (moximu.com) posted server-to-server with the Form bağlantısı token.
Route::post('/satis/form', LeadFormController::class)->middleware('throttle:30,1')->name('api.sales.form');

// Hızlı not (2026-11-10): the browser extension's "+"; the capture key in the Authorization header is the login.
Route::get('/hizli-not', [QuickCaptureController::class, 'index'])->middleware('throttle:60,1')->name('api.quick-capture.index');
Route::post('/hizli-not', [QuickCaptureController::class, 'store'])->middleware('throttle:30,1')->name('api.quick-capture.store');
Route::post('/hizli-not/{reminder}/tik', [QuickCaptureController::class, 'toggle'])->whereNumber('reminder')->middleware('throttle:60,1')->name('api.quick-capture.toggle');

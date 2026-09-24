<?php

use App\Http\Controllers\Integrations\WhatsAppWebhookController;
use App\Http\Controllers\Integrations\WordPressConnectorEventsController;
use App\Http\Controllers\Integrations\WordPressConnectorPairController;
use App\Http\Controllers\Integrations\WordPressConnectorReleaseController;
use App\Http\Controllers\Sales\LeadInboxWebhookController;
use App\Http\Controllers\Sales\MetaLeadgenWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/connectors/wordpress/pair', WordPressConnectorPairController::class)
    ->middleware('throttle:10,1')
    ->name('api.connectors.wordpress.pair');

Route::post('/connectors/wordpress/events', WordPressConnectorEventsController::class)
    ->middleware('throttle:120,1')
    ->name('api.connectors.wordpress.events');

// 1.4.1: short-lived signed link to one built connector ZIP, fetched by the plugin during an approved self-update.
Route::get('/connectors/wordpress/releases/{file}', WordPressConnectorReleaseController::class)
    ->middleware(['signed', 'throttle:30,1'])
    ->where('file', 'moxdop-wordpress-connector-[0-9.]+-[a-f0-9]{16}\.zip')
    ->name('api.connectors.wordpress.release');

Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify'])
    ->middleware('throttle:30,1')->name('api.whatsapp.webhook');
Route::post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'receive'])
    ->middleware('throttle:300,1')->name('api.whatsapp.receive');

// Faz 8g: ajansın kendi web sitesindeki form → lead kutusu (gizli token, spam tuzağı, telefonla tekilleştirme).
Route::post('/leads/{token}', LeadInboxWebhookController::class)
    ->where('token', '[A-Za-z0-9]{32,64}')->middleware('throttle:20,1')->name('api.leads.receive');

// Faz 14: Meta Lead Ads webhook for the agency's own page (verify + signed delivery).
Route::get('/meta/leadgen', [MetaLeadgenWebhookController::class, 'verify'])->middleware('throttle:30,1')->name('api.meta.leadgen.verify');
Route::post('/meta/leadgen', [MetaLeadgenWebhookController::class, 'receive'])->middleware('throttle:120,1')->name('api.meta.leadgen');

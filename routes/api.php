<?php

use App\Http\Controllers\Integrations\WordPressConnectorPairController;
use Illuminate\Support\Facades\Route;

Route::post('/connectors/wordpress/pair', WordPressConnectorPairController::class)
    ->middleware('throttle:10,1')
    ->name('api.connectors.wordpress.pair');

Route::post('/connectors/wordpress/events', \App\Http\Controllers\Integrations\WordPressConnectorEventsController::class)
    ->middleware('throttle:120,1')
    ->name('api.connectors.wordpress.events');


Route::get('/whatsapp/webhook', [\App\Http\Controllers\Integrations\WhatsAppWebhookController::class, 'verify'])
    ->middleware('throttle:30,1')->name('api.whatsapp.webhook');
Route::post('/whatsapp/webhook', [\App\Http\Controllers\Integrations\WhatsAppWebhookController::class, 'receive'])
    ->middleware('throttle:300,1')->name('api.whatsapp.receive');

// Faz 8g: ajansın kendi web sitesindeki form → lead kutusu (gizli token, spam tuzağı, telefonla tekilleştirme).
Route::post('/leads/{token}', \App\Http\Controllers\Sales\LeadInboxWebhookController::class)
    ->where('token', '[A-Za-z0-9]{32,64}')->middleware('throttle:20,1')->name('api.leads.receive');

// Faz 14: Meta Lead Ads webhook for the agency's own page (verify + signed delivery).
Route::get('/meta/leadgen', [\App\Http\Controllers\Sales\MetaLeadgenWebhookController::class, 'verify'])->middleware('throttle:30,1')->name('api.meta.leadgen.verify');
Route::post('/meta/leadgen', [\App\Http\Controllers\Sales\MetaLeadgenWebhookController::class, 'receive'])->middleware('throttle:120,1')->name('api.meta.leadgen');

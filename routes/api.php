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

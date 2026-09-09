<?php

use App\Http\Controllers\Integrations\WordPressConnectorPairController;
use Illuminate\Support\Facades\Route;

Route::post('/connectors/wordpress/pair', WordPressConnectorPairController::class)
    ->middleware('throttle:10,1')
    ->name('api.connectors.wordpress.pair');

Route::post('/connectors/wordpress/events', \App\Http\Controllers\Integrations\WordPressConnectorEventsController::class)
    ->middleware('throttle:120,1')
    ->name('api.connectors.wordpress.events');

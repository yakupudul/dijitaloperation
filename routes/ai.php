<?php

use App\Http\Controllers\Mcp\McpOAuthController;
use App\Http\Middleware\AuthenticateMcpToken;
use App\Mcp\Servers\MoxdopServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// MoxDOP MCP sunucusu: Claude (abonelik) bekleyen AI işlerini okur ve sonucu yazar (AI iş kuyruğu).
Mcp::web((string) config('moxdop-mcp.path', 'mcp/moxdop'), MoxdopServer::class)
    ->middleware([AuthenticateMcpToken::class, 'throttle:120,1']);

// OAuth for claude.ai connectors (they cannot send a fixed key). The approval screen itself is in routes/web.php.
Route::middleware('throttle:60,1')->group(function (): void {
    // These names make laravel/mcp's 401 header point here (AddWwwAuthenticateHeader looks for the nested one).
    Route::get('/.well-known/oauth-protected-resource', [McpOAuthController::class, 'protectedResource'])->name('mcp.oauth.protected-resource');
    Route::get('/.well-known/oauth-protected-resource/{path}', [McpOAuthController::class, 'protectedResource'])->where('path', '.*')->name('mcp.oauth.protected-resource.nested');
    Route::get('/.well-known/oauth-authorization-server/{path?}', [McpOAuthController::class, 'authorizationServer'])->where('path', '.*')->name('mcp.oauth.metadata');
});
Route::post('/mcp/oauth/register', [McpOAuthController::class, 'register'])->middleware('throttle:10,1')->name('mcp.oauth.register');
Route::post('/mcp/oauth/token', [McpOAuthController::class, 'token'])->middleware('throttle:20,1')->name('mcp.oauth.token');

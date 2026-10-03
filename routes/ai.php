<?php

use App\Http\Middleware\AuthenticateMcpToken;
use App\Mcp\Servers\MoxdopServer;
use Laravel\Mcp\Facades\Mcp;

// MoxDOP MCP sunucusu: Claude (abonelik) bekleyen AI işlerini okur ve sonucu yazar (AI iş kuyruğu).
Mcp::web((string) config('moxdop-mcp.path', 'mcp/moxdop'), MoxdopServer::class)
    ->middleware([AuthenticateMcpToken::class, 'throttle:120,1']);

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MoxDOP MCP sunucusu: only a client sending the configured bearer token (MOXDOP_MCP_TOKEN) gets in; no token
 * configured = the server is closed (404).
 */
final class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = trim((string) config('moxdop-mcp.token'));
        abort_if($token === '', 404);
        $given = (string) $request->bearerToken();
        if ($given === '' || ! hash_equals($token, $given)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}

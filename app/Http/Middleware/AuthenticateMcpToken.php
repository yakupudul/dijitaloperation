<?php

namespace App\Http\Middleware;

use App\Models\McpToken;
use App\Services\Mcp\McpKeys;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MoxDOP MCP sunucusu: only a client sending a valid bearer key gets in (a key made in Ayarlar › MCP anahtarları, or
 * the old MOXDOP_MCP_TOKEN while it is on); no key at all = the server is closed (404). A read key may call only the
 * tools that change nothing; any other tool call gets a JSON-RPC error saying so.
 */
final class AuthenticateMcpToken
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(McpKeys::serverOpen(), 404);
        $scope = McpKeys::scopeOf((string) $request->bearerToken());
        if ($scope === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        if ($scope === McpToken::READ && ($blocked = $this->blockedCall($request)) !== null) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $blocked['id'],
                'error' => ['code' => -32601, 'message' => 'Bu MCP anahtarı yalnız okuma içindir; "'.$blocked['tool'].'" bir şey değiştirir. Yazma anahtarı Ayarlar › MCP anahtarları\'ndan üretilir.'],
            ]);
        }

        return $next($request);
    }

    /** @return array{id: mixed, tool: string}|null the first tool call in the body a read key may not make */
    private function blockedCall(Request $request): ?array
    {
        $body = json_decode((string) $request->getContent(), true);
        if (! is_array($body)) {
            return null;
        }
        $messages = array_is_list($body) ? $body : [$body];
        $allowed = McpKeys::readTools();
        foreach ($messages as $message) {
            if (! is_array($message) || ($message['method'] ?? null) !== 'tools/call') {
                continue;
            }
            $tool = (string) ($message['params']['name'] ?? '');
            if (! in_array($tool, $allowed, true)) {
                return ['id' => $message['id'] ?? null, 'tool' => $tool];
            }
        }

        return null;
    }
}

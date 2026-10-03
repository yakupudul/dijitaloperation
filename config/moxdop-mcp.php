<?php

/*
|--------------------------------------------------------------------------
| MoxDOP MCP sunucusu (AI iş kuyruğu)
|--------------------------------------------------------------------------
| Claude (abonelik) MoxDOP'a bu sunucu üzerinden bağlanır: bekleyen AI işlerini okur, sonucu geri yazar.
| Token boşsa sunucu kapalıdır ve hiçbir işlem "Claude (MCP)" seçilemez. Token yalnız ortamda tutulur.
*/

return [
    // Bearer token Claude's MCP client sends (Authorization: Bearer …). Empty = MCP server and delegation off.
    'token' => env('MOXDOP_MCP_TOKEN'),

    // URL path of the MCP server (streamable HTTP).
    'path' => env('MOXDOP_MCP_PATH', 'mcp/moxdop'),

    // Most tasks one list_tasks call returns.
    'list_limit' => 20,
];

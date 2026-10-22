<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** An app that registered itself for OAuth to the MoxDOP MCP server (claude.ai connector, Claude Code). */
#[Fillable(['client_id', 'name', 'redirect_uris'])]
class McpOAuthClient extends Model
{
    protected $table = 'mcp_oauth_clients';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['redirect_uris' => 'array'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/** One MCP key made in the panel; only its sha256 hash and last four characters are kept. */
#[Fillable(['name', 'scope', 'token_hash', 'last_four', 'created_by', 'last_used_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
class McpToken extends Model
{
    public const string READ = 'read';

    public const string WRITE = 'write';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}

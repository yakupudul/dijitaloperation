<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of Moxi's corner chat (see the moxi_messages migration). A card holds the action and its checked arguments in
 * meta (action, args, lines); only the operator's Yap runs it. A request is a job for Claude, answered over MCP.
 */
class MoxiMessage extends Model
{
    public const string USER = 'user';

    public const string MOXI = 'moxi';

    public const string CARD = 'card';

    public const string REQUEST = 'request';

    public const string AUDIT = 'audit';

    public const string PENDING = 'pending';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    public const string CANCELLED = 'cancelled';

    /** @var list<string> */
    protected $fillable = ['user_id', 'role', 'body', 'meta', 'status', 'cost_usd', 'page', 'hidden'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['meta' => 'array', 'cost_usd' => 'float', 'hidden' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

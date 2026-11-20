<?php

namespace App\Panel\Models;

use Illuminate\Database\Eloquent\Model;

/** Access log: sign-ins, exports, user changes, an operator viewing as a user. Never holds a person's data. */
class PanelAudit extends Model
{
    public $timestamps = false;

    protected $table = 'panel_audit';

    protected $fillable = ['brand_id', 'actor_type', 'actor_id', 'action', 'meta', 'ip', 'created_at'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    /** @param  array<string, mixed>  $meta */
    public static function record(string $action, ?int $brandId, string $actorType, ?int $actorId, array $meta = []): void
    {
        self::query()->create([
            'brand_id' => $brandId, 'actor_type' => $actorType, 'actor_id' => $actorId, 'action' => $action,
            'meta' => $meta ?: null, 'ip' => request()?->ip(), 'created_at' => now(),
        ]);
    }
}

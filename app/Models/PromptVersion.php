<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of one AI operation's prompt: purpose, template with variables, context sources, output schema, model.
 * is_current marks the version in use; every suggestion links the version that produced it.
 */
class PromptVersion extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'operation',
        'version',
        'purpose',
        'template',
        'variables',
        'context_sources',
        'output_schema',
        'model',
        'is_current',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'variables' => 'array',
            'context_sources' => 'array',
            'output_schema' => 'array',
            'is_current' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Suggestion, $this> */
    public function suggestions(): HasMany
    {
        return $this->hasMany(Suggestion::class);
    }
}

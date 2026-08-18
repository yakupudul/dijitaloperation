<?php

namespace App\Models;

use Database\Factories\SalesSearchProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesSearchProfile extends Model
{
    /** @use HasFactory<SalesSearchProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'service_definition_code',
        'language',
        'country',
        'location',
        'include_concepts',
        'exclude_concepts',
        'minimum_intent_confidence',
        'active',
        'owner_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'include_concepts' => 'array',
            'exclude_concepts' => 'array',
            'minimum_intent_confidence' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return HasMany<SalesIntentRadarRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(SalesIntentRadarRun::class);
    }

    /**
     * @return HasMany<SalesIntentSignal, $this>
     */
    public function signals(): HasMany
    {
        return $this->hasMany(SalesIntentSignal::class);
    }
}

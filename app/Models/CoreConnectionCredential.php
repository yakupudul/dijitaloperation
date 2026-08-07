<?php

namespace App\Models;

use Database\Factories\CoreConnectionCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'connection_id',
    'encrypted_payload',
])]
#[Hidden([
    'encrypted_payload',
])]
class CoreConnectionCredential extends Model
{
    /** @use HasFactory<CoreConnectionCredentialFactory> */
    use HasFactory;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'encrypted_payload' => 'encrypted:array',
    ];

    /**
     * @return BelongsTo<CoreConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(CoreConnection::class, 'connection_id');
    }
}

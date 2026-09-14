<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'integration_id', 'user_id', 'session_hash', 'settings_revision', 'mode', 'status', 'step', 'payload', 'details', 'expires_at'])]
class WhatsAppSignupAttempt extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['payload', 'session_hash'];

    protected $casts = [
        'payload' => 'encrypted:array',
        'details' => 'array',
        'expires_at' => 'immutable_datetime',
    ];
}

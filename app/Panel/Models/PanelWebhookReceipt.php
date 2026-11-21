<?php

namespace App\Panel\Models;

use Illuminate\Database\Eloquent\Model;

/** What Meta sent, kept until it is turned into messages and leads; the body is wiped once processed. */
class PanelWebhookReceipt extends Model
{
    protected $fillable = ['object', 'payload_hash', 'payload', 'status', 'accepted_count', 'ignored_count', 'processed_at'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'processed_at' => 'datetime'];
    }
}

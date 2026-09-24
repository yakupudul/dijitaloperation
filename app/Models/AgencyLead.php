<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An incoming request to the agency itself (Satış › Lead kutusu). Written by AgencyLeadInbox. */
class AgencyLead extends Model
{
    protected $table = 'agency_leads';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['utm' => 'array', 'received_at' => 'datetime'];
    }
}

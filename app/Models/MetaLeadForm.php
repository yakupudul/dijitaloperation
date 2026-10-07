<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The structure of one Meta instant form (name, intro, questions, thank-you screen) — never its leads.
 */
class MetaLeadForm extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['intro' => 'array', 'questions' => 'array', 'thank_you' => 'array', 'fetched_at' => 'immutable_datetime'];
    }
}

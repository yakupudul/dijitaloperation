<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One collected Google Business Profile review of a bound location (read-only; Faz 14 uses it as the subject of
 * AI reply drafts in the production archive). Written by the GBP collector only.
 */
class GbpReview extends Model
{
    protected $table = 'gbp_reviews';

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reviewer' => 'array', 'review_reply' => 'array', 'raw_payload' => 'array', 'create_time' => 'datetime', 'update_time' => 'datetime', 'collected_at' => 'datetime'];
    }
}

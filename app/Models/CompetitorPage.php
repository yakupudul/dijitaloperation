<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One competitor URL's main content (no header / footer), latest fetch only; unreachable = status "eksik". */
class CompetitorPage extends Model
{
    public const string OK = 'ok';

    public const string MISSING = 'eksik';

    /** @var list<string> */
    protected $fillable = ['url', 'url_hash', 'domain', 'class', 'title', 'headings', 'content_text', 'status', 'error', 'fetched_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['headings' => 'array', 'fetched_at' => 'immutable_datetime'];
    }
}

<?php

use App\Services\Queries\QueryNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Question / informational words ("nedir", "nasıl"…) are never a filter any more (those queries feed the content
 * clusters): basket terms holding one are removed. Place names need no basket term (QueryNormalizer deletes queries
 * naming a province, district or country), so terms that are exactly such a place are removed too.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('filter_terms')->get(['id', 'term'])
            ->filter(fn ($row): bool => QueryNormalizer::isQuestionTerm((string) $row->term) || QueryNormalizer::placeIn((string) $row->term) === QueryNormalizer::lower(trim((string) $row->term)))
            ->pluck('id')->all();
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('filter_terms')->whereIn('id', $chunk)->delete();
        }
    }

    public function down(): void {}
};

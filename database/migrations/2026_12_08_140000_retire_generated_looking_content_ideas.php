<?php

use App\Services\Site\ContentPlanner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * yakup (2026-10-07): the content idea titles "smell of AI" and ignore the brand's own data. The open ones with
 * two-part / labelled / "kapsamlı rehber" titles are closed; the pool refills from the evidence-based planner.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suggestions')) {
            ContentPlanner::retireStyledIdeas();
        }
    }

    public function down(): void
    {
        // Closed ideas stay closed (they are listed under the brand's previous plans).
    }
};

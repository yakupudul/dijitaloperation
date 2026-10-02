<?php

use App\Services\DataPool\PartitionManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Compact Search Console fact tables (gsc_f_*) are monthly partitioned while their logical datasets are declared
 * without partitioning, so writes for months without a partition failed ("no partition of relation … found for
 * row"). Every partitioned parent gets a DEFAULT safety partition and the current + next three months now.
 * PostgreSQL only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        app(PartitionManager::class)->ensureAhead(3);
    }

    public function down(): void
    {
        // Partitions hold data; never dropped automatically.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * W5: the agency's own billing lives in agency_invoices. A clinic-side "invoices" (patient/CRM) entity stays deferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices') && ! Schema::hasTable('agency_invoices')) {
            Schema::rename('invoices', 'agency_invoices');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agency_invoices') && ! Schema::hasTable('invoices')) {
            Schema::rename('agency_invoices', 'invoices');
        }
    }
};

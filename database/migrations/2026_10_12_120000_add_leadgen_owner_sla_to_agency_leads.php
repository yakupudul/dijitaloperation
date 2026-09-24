<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency lead inbox hardening: an idempotency key for Meta Lead Ads redeliveries (external_id, unique),
 * an owner (assigned_to) with a "my leads" filter, and the first-response time for a simple response SLA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_leads', function (Blueprint $table): void {
            $table->string('external_id', 80)->nullable()->unique()->after('source');
            $table->foreignId('assigned_to')->nullable()->after('handled_by')->constrained('users')->nullOnDelete();
            $table->timestampTz('first_response_at')->nullable()->after('received_at');
            $table->index(['assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('agency_leads', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn(['external_id', 'first_response_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Müşteri onay linki (yakup, 2026-10-26): a standing link per brand or per Business Profile that always lists the
 * current reply drafts; what the client approves there goes to Google by itself. `live` marks it, `digital_asset_id`
 * narrows it to one profile (null = every profile of the brand).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gbp_review_approvals', function (Blueprint $table): void {
            if (! Schema::hasColumn('gbp_review_approvals', 'live')) {
                $table->boolean('live')->default(false);
            }
            if (! Schema::hasColumn('gbp_review_approvals', 'digital_asset_id')) {
                $table->unsignedBigInteger('digital_asset_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('gbp_review_approvals', function (Blueprint $table): void {
            $table->dropColumn(['live', 'digital_asset_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof that data and connections are real: live_checks keeps every read-only verification call of
 * moxdop:verify:live (latest N days); data_consistency_issues keeps the open "Veri şüpheli" findings of the
 * daily consistency check (missing days, broken tagging, conversion divergence, currency mismatch).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_checks', function (Blueprint $table): void {
            $table->id();
            $table->string('check_key', 191);
            $table->string('provider', 30);
            $table->string('capability', 40);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('label', 255);
            $table->string('status', 10); // ok | fail | skipped
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamp('checked_at');
            $table->index(['check_key', 'checked_at']);
            $table->index('checked_at');
        });

        Schema::create('data_consistency_issues', function (Blueprint $table): void {
            $table->id();
            $table->string('issue_key', 191)->unique();
            $table->string('kind', 40);
            $table->string('severity', 10);
            $table->unsignedBigInteger('brand_id')->nullable()->index();
            $table->unsignedBigInteger('digital_asset_id')->nullable()->index();
            $table->string('title', 255);
            $table->text('detail')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_consistency_issues');
        Schema::dropIfExists('live_checks');
    }
};

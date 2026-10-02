<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical Opportunity. One row is a persistent, non-prescriptive commercial-relevance
 * identity for a Digital Asset, unique on (digital_asset_id, fingerprint). No score columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('opportunities')) {
            return;
        }

        Schema::create('opportunities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->foreignId('digital_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('origin', 32);
            $table->string('rule_id', 128)->nullable();
            $table->unsignedInteger('rule_version')->nullable();
            $table->string('fingerprint', 191);
            $table->char('semantic_fingerprint', 64)->nullable();
            $table->string('subject_kind', 64)->nullable();
            $table->string('subject_id', 191)->nullable();
            $table->string('category', 64);
            $table->string('status', 32)->default('open');
            $table->string('detection_state', 32)->nullable();
            $table->string('qualitative_priority', 32)->nullable();
            $table->string('service_definition_code', 64)->nullable();
            $table->string('commercial_scope_state', 32)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('brand_goal_id')->nullable();
            $table->unsignedBigInteger('brand_offering_id')->nullable();
            $table->string('market_location', 128)->nullable();
            $table->string('market_language', 64)->nullable();
            $table->dateTime('first_detected_at');
            $table->dateTime('last_detected_at');
            $table->dateTime('closed_at')->nullable();
            $table->unsignedBigInteger('latest_evaluation_id')->nullable();
            $table->timestamps();

            $table->unique('fingerprint', 'opportunities_fingerprint_unique');
            $table->index('customer_id', 'opportunities_customer_id_index');
            $table->index('brand_id', 'opportunities_brand_id_index');
            $table->index('rule_id', 'opportunities_rule_id_index');
            $table->index('status', 'opportunities_status_index');
            $table->index('origin', 'opportunities_origin_index');
            $table->index(['subject_kind', 'subject_id'], 'opportunities_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunities');
    }
};

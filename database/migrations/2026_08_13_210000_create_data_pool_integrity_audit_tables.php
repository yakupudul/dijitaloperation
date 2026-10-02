<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_integrity_audit_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('status', 32);
            $table->string('mode', 40)->default('LOCAL_INTEGRITY');
            $table->string('scope_type', 40)->default('production_datasets');
            $table->json('scope')->nullable();
            $table->unsignedBigInteger('initiated_by_user_id')->nullable();
            $table->unsignedInteger('contract_registry_version')->nullable();
            $table->unsignedInteger('storage_contract_version')->nullable();
            $table->unsignedInteger('formula_registry_version')->nullable();
            $table->unsignedInteger('integrity_registry_version')->nullable();
            $table->unsignedInteger('audit_rules_version')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('checks_total')->default(0);
            $table->unsignedInteger('checks_pass')->default(0);
            $table->unsignedInteger('checks_pass_with_limitation')->default(0);
            $table->unsignedInteger('checks_warning')->default(0);
            $table->unsignedInteger('checks_fail')->default(0);
            $table->unsignedInteger('checks_unverified')->default(0);
            $table->unsignedInteger('checks_not_applicable')->default(0);
            $table->json('provider_readiness')->nullable();
            $table->json('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index(['mode', 'created_at']);
        });

        Schema::create('data_integrity_check_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('audit_run_id')->constrained('data_integrity_audit_runs')->cascadeOnDelete();
            $table->string('provider_or_source', 40)->nullable();
            $table->unsignedBigInteger('digital_asset_id')->nullable();
            $table->unsignedBigInteger('external_resource_id')->nullable();
            $table->string('dataset_id', 120)->nullable();
            $table->string('check_id', 80);
            $table->string('category', 60);
            $table->string('severity', 20)->default('info');
            $table->string('status', 40);
            $table->json('expected')->nullable();
            $table->json('observed')->nullable();
            $table->json('difference')->nullable();
            $table->json('tolerance')->nullable();
            $table->string('message', 500)->nullable();
            $table->json('evidence')->nullable();
            $table->boolean('blocks_migration')->default(false);
            $table->timestamps();

            $table->index(['audit_run_id', 'status']);
            $table->index(['provider_or_source', 'dataset_id']);
            $table->index(['check_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_integrity_check_results');
        Schema::dropIfExists('data_integrity_audit_runs');
    }
};

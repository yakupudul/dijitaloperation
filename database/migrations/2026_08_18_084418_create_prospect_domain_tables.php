<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospects', function (Blueprint $table): void {
            $table->id();
            $table->string('company_name');
            $table->string('website_url')->nullable();
            $table->string('source');
            $table->text('inquiry')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('identity_status')->default('unknown');
            $table->string('status')->default('new');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('converted_brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('identity_status');
            $table->index('source');
            $table->index('owner_user_id');
        });

        Schema::create('prospect_research_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('seed_url')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['prospect_id', 'status']);
        });

        Schema::create('prospect_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prospect_research_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('title');
            $table->string('source_url')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->string('provenance');
            $table->json('payload');
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(['prospect_id', 'fingerprint']);
            $table->index(['prospect_id', 'type']);
        });

        Schema::create('prospect_discovery_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prospect_research_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prospect_evidence_id')->nullable()->constrained('prospect_evidence')->nullOnDelete();
            $table->string('fingerprint', 64);
            $table->string('candidate_kind');
            $table->string('candidate_type');
            $table->string('target_field')->nullable();
            $table->text('proposed_value');
            $table->json('support_json')->nullable();
            $table->string('support_label')->nullable();
            $table->string('provenance');
            $table->timestamps();

            $table->unique(['prospect_id', 'fingerprint']);
        });

        Schema::create('prospect_sales_intelligence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prospect_research_run_id')->nullable()->constrained()->nullOnDelete();
            $table->text('summary')->nullable();
            $table->json('detected_needs')->nullable();
            $table->json('recommended_services')->nullable();
            $table->json('not_recommended_services')->nullable();
            $table->json('sales_priorities')->nullable();
            $table->string('first_meeting_focus')->nullable();
            $table->json('diagnostic_questions')->nullable();
            $table->text('suggested_positioning')->nullable();
            $table->json('uncertainties')->nullable();
            $table->string('overall_confidence')->nullable();
            $table->string('status')->default('unavailable');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['prospect_id', 'created_at']);
        });

        Schema::create('prospect_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['prospect_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_activities');
        Schema::dropIfExists('prospect_sales_intelligence');
        Schema::dropIfExists('prospect_discovery_candidates');
        Schema::dropIfExists('prospect_evidence');
        Schema::dropIfExists('prospect_research_runs');
        Schema::dropIfExists('prospects');
    }
};

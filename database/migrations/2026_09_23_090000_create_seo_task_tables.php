<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('brand_offerings', 'is_priority')) {
            Schema::table('brand_offerings', function (Blueprint $table): void {
                $table->boolean('is_priority')->default(false)->after('priority_rank');
                $table->index(['brand_id', 'is_priority'], 'brand_offerings_brand_priority_idx');
            });

            // Mevcut "öncelikli hizmet" sıralaması yıldız bayrağına taşınır.
            DB::table('brand_offerings')->whereNotNull('priority_rank')->update(['is_priority' => true]);
        }

        Schema::create('seo_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->string('status', 24)->default('queued'); // queued|running|completed|failed
            $table->string('trigger', 24)->default('manual'); // manual|scheduled|bulk
            $table->unsignedInteger('version')->default(1);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->json('input_summary')->nullable();
            $table->json('llm_summary')->nullable();
            $table->json('result_summary')->nullable();
            $table->text('summary_text')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestampsTz();

            $table->index(['digital_asset_id', 'status', 'id'], 'seo_plans_asset_status_idx');
        });

        Schema::create('service_page_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('brand_offering_id')->constrained('brand_offerings')->cascadeOnDelete();
            $table->text('page_url')->nullable(); // null + status=none → "sayfası yok"
            $table->string('status', 24)->default('assigned'); // assigned|none|pending_question
            $table->string('decision_source', 24)->default('auto'); // auto|operator
            $table->decimal('score', 6, 4)->nullable();
            $table->json('candidates')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->unique(['digital_asset_id', 'brand_offering_id'], 'service_page_assignment_uq');
        });

        Schema::create('seo_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('brand_offering_id')->nullable()->constrained('brand_offerings')->nullOnDelete();
            $table->char('task_key', 64); // hash(rule|url|signature)
            $table->string('type', 24); // fix|strengthen|create|ai_visibility|question
            $table->string('rule_id', 96);
            $table->string('severity', 16)->default('medium'); // critical|high|medium|low
            $table->decimal('priority_score', 12, 2)->default(0);
            $table->decimal('estimated_extra_clicks', 12, 2)->nullable();
            $table->string('title', 255);
            $table->text('reason')->nullable();
            $table->json('evidence')->nullable();
            $table->json('checklist')->nullable();
            $table->text('target_url')->nullable();
            $table->boolean('is_new_page')->default(false);
            $table->json('content_brief')->nullable();
            $table->json('llm_payload')->nullable();
            $table->string('status', 16)->default('open'); // open|done|skipped|stale
            $table->foreignId('first_seen_plan_id')->constrained('seo_plans')->cascadeOnDelete();
            $table->foreignId('last_seen_plan_id')->constrained('seo_plans')->cascadeOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestampsTz();

            $table->unique(['digital_asset_id', 'task_key'], 'seo_tasks_asset_key_uq');
            $table->index(['status', 'priority_score'], 'seo_tasks_status_priority_idx');
            $table->index(['digital_asset_id', 'status', 'type'], 'seo_tasks_asset_status_type_idx');
            $table->index(['customer_id', 'status'], 'seo_tasks_customer_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_tasks');
        Schema::dropIfExists('service_page_assignments');
        Schema::dropIfExists('seo_plans');

        if (Schema::hasColumn('brand_offerings', 'is_priority')) {
            Schema::table('brand_offerings', function (Blueprint $table): void {
                $table->dropIndex('brand_offerings_brand_priority_idx');
                $table->dropColumn('is_priority');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajans işletmesi: who was told what and what comes next (communication log), time spent per brand, what was promised
 * each month (commitments) and what was billed / paid (invoices). Internal records only; nothing is sent anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_interactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('channel', 16); // call | whatsapp | email | meeting | note
            $table->string('direction', 8)->default('out'); // in | out
            $table->text('summary');
            $table->string('next_action', 300)->nullable();
            $table->timestampTz('next_action_at')->nullable();
            $table->timestampTz('next_action_done_at')->nullable();
            $table->timestampTz('occurred_at');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['customer_id', 'occurred_at']);
            $table->index(['next_action_at', 'next_action_done_at']);
        });

        Schema::create('time_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('worked_on');
            $table->unsignedInteger('minutes');
            $table->string('category', 16); // seo | ads | meta | gbp | web | content | report | meeting | other
            $table->string('note', 300)->nullable();
            $table->timestampsTz();
            $table->index(['customer_id', 'worked_on']);
        });

        Schema::create('service_commitments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('title', 160);
            $table->unsignedSmallInteger('monthly_quantity')->default(1);
            $table->string('counts_from', 16)->nullable(); // content calendar channel counted automatically (blog, gbp_post…), else manual
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('service_commitment_marks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_commitment_id')->constrained('service_commitments')->cascadeOnDelete();
            $table->char('month', 7);
            $table->unsignedSmallInteger('done')->default(0);
            $table->timestampsTz();
            $table->unique(['service_commitment_id', 'month']);
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('number', 40)->nullable();
            $table->char('period', 7);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('TRY');
            $table->string('status', 12)->default('draft'); // draft | issued | paid | cancelled
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->date('paid_on')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestampsTz();
            $table->unique(['customer_id', 'period', 'number']);
            $table->index(['status', 'due_on']);
        });

        if (Schema::hasTable('agency_settings') && ! Schema::hasColumn('agency_settings', 'hourly_cost')) {
            Schema::table('agency_settings', function (Blueprint $table): void {
                $table->decimal('hourly_cost', 10, 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('service_commitment_marks');
        Schema::dropIfExists('service_commitments');
        Schema::dropIfExists('time_entries');
        Schema::dropIfExists('customer_interactions');
        if (Schema::hasColumn('agency_settings', 'hourly_cost')) {
            Schema::table('agency_settings', fn (Blueprint $table) => $table->dropColumn('hourly_cost'));
        }
    }
};

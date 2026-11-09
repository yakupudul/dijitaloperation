<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satış beyni (yakup 2026-11-08): what the sales work learns, kept where the operator sees, edits and approves it.
 * Every closed deal (won or lost) gets a karne; Claude reads it and proposes lessons (a decision or a method) and
 * questions it needs answered (a price, a rule). Only approved lessons and answered questions reach the drafts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_lessons', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 12); // karar | yontem | soru
            $table->text('text');
            $table->text('answer')->nullable();
            $table->string('source', 12)->default('claude'); // claude | operator
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->string('status', 12)->default('proposed'); // proposed | approved | rejected
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'kind']);
        });
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->string('lesson_status', 12)->nullable();
            $table->string('lesson_note', 500)->nullable();
            $table->text('lesson_verdict')->nullable();
            $table->timestamp('lesson_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropColumn(['lesson_status', 'lesson_note', 'lesson_verdict', 'lesson_at']);
        });
        Schema::dropIfExists('sales_lessons');
    }
};

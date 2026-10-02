<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI işleri: the live table becomes the AI work history. A row is either one agent call (`call`) or one queued AI job
 * (`job`: Sırada → Çalışıyor → Bitti / Hata / Durduruldu) whose calls point at it (`parent_id`). Calls keep a capped copy
 * of the input and the output, the prompt version, provider / model and tokens; jobs keep the queue job for removal
 * and a cooperative cancel flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_live_operations', function (Blueprint $table): void {
            $table->string('kind', 8)->default('call');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('job_uuid', 64)->nullable();
            $table->string('job_class', 190)->nullable();
            $table->string('queue_connection', 64)->nullable();
            $table->string('queue_name', 120)->nullable();
            $table->string('queue_job_id', 64)->nullable();
            $table->text('context')->nullable();
            $table->string('link', 500)->nullable();
            $table->unsignedBigInteger('prompt_version_id')->nullable();
            $table->string('provider', 48)->nullable();
            $table->string('model', 190)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->mediumText('input_text')->nullable();
            $table->mediumText('output_text')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('cancel_requested_at')->nullable();
            $table->unsignedBigInteger('cancel_requested_by')->nullable();

            $table->index(['parent_id'], 'ai_live_parent_idx');
            $table->index(['job_uuid'], 'ai_live_job_uuid_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_live_operations', function (Blueprint $table): void {
            $table->dropIndex('ai_live_parent_idx');
            $table->dropIndex('ai_live_job_uuid_idx');
            $table->dropColumn([
                'kind', 'parent_id', 'job_uuid', 'job_class', 'queue_connection', 'queue_name', 'queue_job_id', 'context', 'link',
                'prompt_version_id', 'provider', 'model', 'input_tokens', 'output_tokens', 'input_text', 'output_text', 'queued_at',
                'cancel_requested_at', 'cancel_requested_by',
            ]);
        });
    }
};

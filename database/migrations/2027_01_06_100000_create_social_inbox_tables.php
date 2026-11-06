<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sosyal medya gelen kutusu (ADR-083, phase 2): Instagram / Facebook direct messages and comments on the agency's own
 * accounts. One thread per conversation (DM) or per top-level comment; the AI writes a reply draft, the Admin sends it
 * with one click.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_threads')) {
            Schema::create('social_threads', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('social_account_id')->constrained('social_accounts')->cascadeOnDelete();
                $table->string('kind', 12); // dm | comment
                $table->string('external_id', 128); // conversation id | top-level comment id
                $table->string('author_id', 64)->nullable(); // IGSID / PSID / commenter id
                $table->string('author_name')->nullable();
                $table->string('post_external_id', 128)->nullable(); // comment: the media / post it is on
                $table->string('post_url', 1000)->nullable();
                $table->string('post_caption', 500)->nullable();
                $table->text('last_text')->nullable();
                $table->string('last_direction', 4)->nullable(); // in | out
                $table->timestampTz('last_in_at')->nullable();
                $table->timestampTz('last_at')->nullable();
                $table->string('status', 12)->default('open'); // open | answered | ignored
                $table->text('draft')->nullable();
                $table->string('draft_status', 12)->nullable(); // queued | running | waiting | ready | failed
                $table->string('draft_note', 1000)->nullable();
                $table->boolean('draft_lead')->default(false);
                $table->timestampTz('draft_at')->nullable();
                $table->string('send_error', 500)->nullable();
                $table->foreignId('sales_lead_id')->nullable()->constrained('sales_leads')->nullOnDelete();
                $table->timestampsTz();
                $table->unique(['social_account_id', 'kind', 'external_id'], 'social_threads_unique');
                $table->index(['status', 'last_at'], 'social_threads_status_idx');
            });
        }

        if (! Schema::hasTable('social_messages')) {
            Schema::create('social_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('social_thread_id')->constrained('social_threads')->cascadeOnDelete();
                $table->string('external_id', 191);
                $table->string('direction', 4); // in | out
                $table->string('author_name')->nullable();
                $table->text('body')->nullable();
                $table->timestampTz('sent_at');
                $table->timestampsTz();
                $table->unique(['social_thread_id', 'external_id'], 'social_messages_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('social_messages');
        Schema::dropIfExists('social_threads');
    }
};

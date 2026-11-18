<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beyin › Kitaplar store, under its own name. The live database still holds the old Prompt 45 `playbooks` table (it had
 * rows, so the cleanup kept it), and 2027_01_26_090000_create_playbooks skipped because that name existed: every lesson
 * and every operator edit failed with "column key does not exist" (Playbooks.php:119, 2026-11-18). The books now live in
 * `brain_playbooks`; the old table is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brain_playbooks')) {
            return;
        }
        Schema::create('brain_playbooks', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('title', 120);
            $table->string('when', 300)->nullable();
            $table->text('body')->nullable();
            $table->text('previous_body')->nullable();
            $table->json('lessons')->nullable();
            $table->string('edited_by', 60)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brain_playbooks');
    }
};

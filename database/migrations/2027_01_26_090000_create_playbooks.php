<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Marka Düzeni 2.4: the books Claude loads for a job (master and sector books); a row exists once a book was edited or got a lesson. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('playbooks')) {
            return;
        }
        Schema::create('playbooks', function (Blueprint $table): void {
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
        Schema::dropIfExists('playbooks');
    }
};

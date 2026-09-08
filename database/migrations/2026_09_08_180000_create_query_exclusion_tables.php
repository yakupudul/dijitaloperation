<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('query_exclusion_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('label', 255);
            $table->string('normalized', 255)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('query_exclusion_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->string('identity_hash', 64)->unique();
            $table->text('query_text');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('query_exclusion_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('scanning');
            $table->json('rules');
            $table->string('rules_hash', 64);
            $table->unsignedBigInteger('max_item_id')->default(0);
            $table->unsignedBigInteger('cursor')->default(0);
            $table->unsignedBigInteger('scanned')->default(0);
            $table->unsignedBigInteger('matched')->default(0);
            $table->unsignedBigInteger('removed')->default(0);
            $table->unsignedBigInteger('skipped')->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
        Schema::create('query_exclusion_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('query_exclusion_runs')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('search_query_library_items')->restrictOnDelete();
            $table->text('query_text');
            $table->text('matched_text');
            $table->json('expressions');
            $table->string('version', 64);
            $table->boolean('selected')->default(true);
            $table->string('decision', 32)->default('pending');
            $table->timestamps();
            $table->unique(['run_id', 'item_id']);
            $table->index(['run_id', 'decision', 'id']);
        });
        Schema::create('query_exclusion_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('search_query_library_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->text('query_text');
            $table->json('expressions');
            $table->timestamps();
            $table->unique(['import_id', 'row_number']);
        });
        Schema::table('search_query_library_imports', function (Blueprint $table): void {
            $table->unsignedInteger('excluded_rows')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('search_query_library_imports', fn (Blueprint $table) => $table->dropColumn('excluded_rows'));
        Schema::dropIfExists('query_exclusion_import_rows');
        Schema::dropIfExists('query_exclusion_matches');
        Schema::dropIfExists('query_exclusion_runs');
        Schema::dropIfExists('query_exclusion_exceptions');
        Schema::dropIfExists('query_exclusion_rules');
    }
};

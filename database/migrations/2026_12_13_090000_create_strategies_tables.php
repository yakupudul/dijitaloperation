<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stratejiler: the strategies Claude writes for the operator (for example a brand's abroad Google Ads roadmap), kept
 * with their scope (brand, channel, service, markets, languages, budget), status and every version of the text, so a
 * later request can look back at the earlier ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('strategies')) {
            Schema::create('strategies', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
                $table->string('title', 200);
                $table->string('channel', 20)->default('multi');
                $table->string('service', 200)->nullable();
                $table->json('markets')->nullable();
                $table->json('languages')->nullable();
                $table->decimal('budget_amount', 14, 2)->nullable();
                $table->string('budget_currency', 3)->default('TRY');
                $table->string('budget_period', 10)->nullable();
                $table->string('status', 20)->default('draft');
                $table->text('summary')->nullable();
                $table->text('request')->nullable();
                $table->string('source_ref', 500)->nullable();
                $table->unsignedInteger('current_version')->default(1);
                $table->timestamp('status_changed_at')->nullable();
                $table->timestamps();
                $table->index(['brand_id', 'status']);
            });
        }
        if (! Schema::hasTable('strategy_versions')) {
            Schema::create('strategy_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->longText('body');
                $table->string('change_note', 500)->nullable();
                $table->json('evidence')->nullable();
                $table->string('author', 60)->default('Claude');
                $table->timestamps();
                $table->unique(['strategy_id', 'version']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('strategy_versions');
        Schema::dropIfExists('strategies');
    }
};

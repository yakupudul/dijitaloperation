<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Microsoft Clarity (yakup, 2026-10-03): one Clarity project per website (Data Export API token, encrypted) and the
 * daily behaviour of each page × device (sessions, rage / dead clicks, quick backs, JavaScript errors, scroll depth).
 * The rules turn bad pages into Teknik sağlık work in Genel işler; nothing is written to the site.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clarity_projects')) {
            Schema::create('clarity_projects', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('website_asset_id')->unique()->constrained('digital_assets')->cascadeOnDelete();
                $table->string('project_id', 64)->nullable();
                $table->text('api_token');
                $table->boolean('enabled')->default(true);
                $table->timestampTz('last_pulled_at')->nullable();
                $table->string('last_status', 16)->nullable(); // ok | empty | error
                $table->string('last_error', 500)->nullable();
                $table->timestampsTz();
            });
        }
        if (! Schema::hasTable('clarity_page_days')) {
            Schema::create('clarity_page_days', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('website_asset_id')->constrained('digital_assets')->cascadeOnDelete();
                $table->date('day');
                $table->string('url_hash', 64);
                $table->string('url', 1000);
                $table->unsignedBigInteger('page_id')->nullable()->index();
                $table->string('device', 16);
                $table->unsignedInteger('sessions')->default(0);
                $table->decimal('rage_pct', 6, 2)->nullable();
                $table->decimal('dead_pct', 6, 2)->nullable();
                $table->decimal('quickback_pct', 6, 2)->nullable();
                $table->decimal('script_error_pct', 6, 2)->nullable();
                $table->decimal('error_click_pct', 6, 2)->nullable();
                $table->decimal('excessive_scroll_pct', 6, 2)->nullable();
                $table->decimal('scroll_depth', 6, 2)->nullable();
                $table->unsignedInteger('active_seconds')->nullable();
                $table->timestampsTz();
                $table->unique(['website_asset_id', 'day', 'url_hash', 'device'], 'clarity_page_days_unique');
                $table->index(['website_asset_id', 'day']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clarity_page_days');
        Schema::dropIfExists('clarity_projects');
    }
};

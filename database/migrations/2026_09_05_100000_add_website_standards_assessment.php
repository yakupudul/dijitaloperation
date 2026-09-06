<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_standard_settings', function (Blueprint $table): void {
            $table->string('standard_id', 160)->primary();
            $table->boolean('enabled')->default(true);
            $table->json('custom_definition')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::table('search_demand_improvement_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('search_demand_cluster_id')->nullable()->change();
            $table->unsignedBigInteger('search_demand_page_ownership_id')->nullable()->change();
            $table->unsignedBigInteger('competitive_intelligence_run_id')->nullable()->change();
            $table->string('route_signature', 500)->change();
        });

        Schema::table('search_demand_competitive_intelligence_runs', function (Blueprint $table): void {
            $table->string('route_signature', 500)->change();
        });

        Schema::table('search_demand_competitive_page_analyses', function (Blueprint $table): void {
            $table->json('standard_assessments')->nullable();
            $table->string('comparability', 24)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('search_demand_competitive_page_analyses', function (Blueprint $table): void {
            $table->dropColumn(['standard_assessments', 'comparability']);
        });
        Schema::dropIfExists('website_standard_settings');
        // Nullable scope retains standalone assessment history when rolling code back.
    }
};

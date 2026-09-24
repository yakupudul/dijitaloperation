<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What the customer pays the agency, the agreed monthly ad budgets, and the daily health score history. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->decimal('monthly_fee', 12, 2)->nullable();
            $table->decimal('ad_budget_google', 12, 2)->nullable();
            $table->decimal('ad_budget_meta', 12, 2)->nullable();
        });
        if (! Schema::hasTable('customer_health_history')) {
            Schema::create('customer_health_history', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->date('recorded_on');
                $table->unsignedTinyInteger('score');
                $table->string('band', 16);
                $table->timestamps();
                $table->unique(['customer_id', 'recorded_on']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_health_history');
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn(['monthly_fee', 'ad_budget_google', 'ad_budget_meta']);
        });
    }
};

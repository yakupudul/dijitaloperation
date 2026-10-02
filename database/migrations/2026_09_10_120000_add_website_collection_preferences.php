<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_connector_delivery', function (Blueprint $table): void {
            $table->boolean('automation_enabled')->default(true);
            $table->unsignedTinyInteger('inventory_interval_days')->default(1);
            $table->index(['automation_enabled', 'next_reconcile_at'], 'website_delivery_due');
        });
        Schema::table('website_connector_events', function (Blueprint $table): void {
            $table->index(['connection_id', 'id'], 'website_events_reconciliation');
        });
    }

    public function down(): void
    {
        Schema::table('website_connector_events', function (Blueprint $table): void {
            $table->dropIndex('website_events_reconciliation');
        });
        Schema::table('website_connector_delivery', function (Blueprint $table): void {
            $table->dropIndex('website_delivery_due');
            $table->dropColumn(['automation_enabled', 'inventory_interval_days']);
        });
    }
};

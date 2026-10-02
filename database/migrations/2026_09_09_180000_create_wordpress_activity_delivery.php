<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_connector_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('core_connections')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->uuid('event_id');
            $table->string('type', 64);
            $table->string('object_type', 64);
            $table->string('object_id', 191);
            $table->string('title', 200)->nullable();
            $table->string('actor_name', 100)->nullable();
            $table->string('origin', 32);
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->unique(['connection_id', 'event_id']);
            $table->index(['digital_asset_id', 'occurred_at']);
            $table->index(['digital_asset_id', 'type', 'occurred_at']);
        });
        Schema::create('website_connector_delivery', function (Blueprint $table): void {
            $table->foreignId('connection_id')->primary()->constrained('core_connections')->cascadeOnDelete();
            $table->timestamp('last_received_at')->nullable();
            $table->string('plugin_version', 32)->nullable();
            $table->unsignedInteger('pending_count')->nullable();
            $table->timestamp('gap_at')->nullable();
            $table->json('delivery')->nullable();
            $table->unsignedBigInteger('latest_event_id')->default(0);
            $table->unsignedBigInteger('reconciled_event_id')->default(0);
            $table->unsignedBigInteger('collection_event_id')->default(0);
            $table->unsignedBigInteger('collection_run_id')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('last_inventory_at')->nullable();
            $table->boolean('collection_is_full')->default(false);
            $table->timestamp('next_reconcile_at')->nullable();
            $table->string('last_error', 200)->nullable();
        });
        Schema::create('website_connector_nonces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('core_connections')->cascadeOnDelete();
            $table->uuid('nonce');
            $table->timestamp('created_at')->index();
            $table->unique(['connection_id', 'nonce']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_connector_nonces');
        Schema::dropIfExists('website_connector_delivery');
        Schema::dropIfExists('website_connector_events');
    }
};

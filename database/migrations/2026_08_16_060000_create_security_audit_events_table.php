<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 64 — security audit events (metadata only, never secrets).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('security_audit_events')) {
            Schema::create('security_audit_events', function (Blueprint $table): void {
                $table->id();
                $table->string('kind', 64);
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
                $table->unsignedBigInteger('integration_id')->nullable();
                $table->string('provider', 64)->nullable();
                $table->string('reason', 191)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['kind', 'created_at']);
                $table->index(['customer_id', 'brand_id']);
                $table->index('integration_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('security_audit_events');
    }
};

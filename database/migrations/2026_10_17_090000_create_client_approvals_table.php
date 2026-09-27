<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-075: the client approves or asks for changes on planned content through a signed, expiring link. No login,
 * no client account; the response is only recorded for the operator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('title', 300);
            $table->text('body')->nullable();
            $table->string('status', 24)->default('pending');
            $table->text('client_note')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('expires_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['status', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_approvals');
    }
};

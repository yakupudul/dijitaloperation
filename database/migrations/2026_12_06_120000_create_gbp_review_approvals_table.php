<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yorumlar › "Markaya onaya gönder": a link (no login) where the brand approves, edits or declines the prepared review
 * replies; the items are a copy of what was sent, the brand's answers are kept on them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gbp_review_approvals')) {
            return;
        }
        Schema::create('gbp_review_approvals', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('brand_id')->index();
            $table->json('items');
            $table->text('note')->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamp('expires_at');
            $table->timestamp('answered_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gbp_review_approvals');
    }
};

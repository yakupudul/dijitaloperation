<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yetki devri kaydı: an Admin explicitly confirmed moving an external account (resource) or a digital asset from one
 * customer / brand / asset to another. Append-only history; the ids are plain columns (plus a name snapshot) so the
 * record survives deletes of the customers, brands or assets it names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ownership_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 16);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('from_customer_id')->nullable();
            $table->unsignedBigInteger('from_brand_id')->nullable();
            $table->unsignedBigInteger('from_asset_id')->nullable();
            $table->unsignedBigInteger('to_customer_id')->nullable();
            $table->unsignedBigInteger('to_brand_id')->nullable();
            $table->unsignedBigInteger('to_asset_id')->nullable();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id'], 'ownership_transfers_subject_idx');
            $table->index('from_asset_id', 'ownership_transfers_from_asset_idx');
            $table->index('to_asset_id', 'ownership_transfers_to_asset_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ownership_transfers');
    }
};

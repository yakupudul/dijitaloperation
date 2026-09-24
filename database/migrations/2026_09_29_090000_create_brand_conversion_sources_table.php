<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brand conversion dictionary: which measured signal (GA4 key event, Google Ads conversion action, Meta
 * action type, Business Profile metric) means which business conversion, and whether it counts in the
 * brand's conversion total. Discovered automatically; operator edits are kept (origin = operator).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_conversion_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('source', 40);
            $table->string('source_key', 255);
            $table->string('label', 255);
            $table->string('conversion_type', 40);
            $table->boolean('counts')->default(false);
            $table->string('origin', 20)->default('auto');
            $table->json('metadata')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['brand_id', 'source', 'source_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_conversion_sources');
    }
};

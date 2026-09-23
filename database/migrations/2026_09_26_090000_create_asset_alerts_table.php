<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('alert_key', 64);
            $table->string('kind', 64);
            $table->string('severity', 16);
            $table->string('title', 200);
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['digital_asset_id', 'alert_key']);
            $table->index(['resolved_at', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_alerts');
    }
};

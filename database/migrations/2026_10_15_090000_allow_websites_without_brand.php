<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A website can be added and connected (WordPress connector) from Integrations before it belongs to a brand;
 * the brand picks it later. Only websites are created without a brand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digital_assets', function (Blueprint $table): void {
            $table->unsignedBigInteger('brand_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Brandless websites would violate NOT NULL; they stay nullable.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backlink hizmeti, adım 2 (2026-11-06): the client's "Bağlantı kaynakları" form, one standing link per brand. Its
 * answers become epikriz facts and relation rows on the Bağlantı masası.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('link_surveys')) {
            Schema::create('link_surveys', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('brand_id')->unique('link_surveys_brand_uq')->constrained('brands')->cascadeOnDelete();
                $table->char('token', 48)->unique('link_surveys_token_uq');
                $table->json('answers')->nullable();
                $table->text('note')->nullable();
                $table->timestampTz('answered_at')->nullable();
                $table->unsignedInteger('rows_created')->default(0);
                $table->timestampsTz();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('link_surveys');
    }
};

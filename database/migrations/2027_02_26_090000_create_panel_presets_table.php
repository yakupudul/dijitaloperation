<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_presets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('label', 190);
            $table->text('body')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('panel_users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['brand_id', 'kind', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_presets');
    }
};

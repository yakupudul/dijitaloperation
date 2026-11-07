<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Müşteri dönemi (Moximu büyüme sistemi, Aşama 3): the "rapor gönderildi" mark per brand and month, and the state of
 * the four risk signals (put off, handled) so a signal leaves the Gelen kutusu once it is dealt with.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_reports')) {
            Schema::create('brand_reports', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->string('month', 7);
                $table->timestamp('sent_at');
                $table->string('channel', 20)->nullable();
                $table->string('note', 500)->nullable();
                $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['brand_id', 'month']);
            });
        }
        if (! Schema::hasTable('brand_signals')) {
            Schema::create('brand_signals', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
                $table->string('signal', 30);
                $table->string('period', 7);
                $table->timestamp('snoozed_until')->nullable();
                $table->timestamp('handled_at')->nullable();
                $table->string('note', 500)->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['brand_id', 'signal', 'period']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_signals');
        Schema::dropIfExists('brand_reports');
    }
};

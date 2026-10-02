<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service Brain, phase 6: the legal gate. When the gate is switched on (after legal review of the 12.11.2025
 * health promotion regulation), health brands only get paid-advertising growth recommendations if an operator
 * recorded why they may advertise (e.g. first month after opening, health-tourism authorisation abroad).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brain_legal_eligibility', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('brand_id')->unique()->constrained('brands')->cascadeOnDelete();
            $t->boolean('paid_ads_allowed')->default(false);
            $t->string('basis', 32)->nullable();
            $t->date('valid_until')->nullable();
            $t->text('note')->nullable();
            $t->foreignId('confirmed_by')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brain_legal_eligibility');
    }
};

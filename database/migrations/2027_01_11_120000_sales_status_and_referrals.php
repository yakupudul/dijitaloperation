<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satış yeniden kurgu (yakup 2026-11-08): the table is filtered by six statuses (Devam eden, Güçlü aday, Kararsız,
 * Olumsuz, Aday olmayan, Müşteri). "Güçlü aday" is the operator's own mark (Claude only suggests it, with a reason);
 * the rest follow the stage and the lifecycle. "Kimden": Moximu or a referral, by a customer or by someone on the
 * Referanslar list; a referred record that becomes a customer earns a reward (customer: one month free; anyone else:
 * %10 of the new customer's first payment, once), given when the new customer has paid twice (moximu.com rules).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->boolean('is_strong')->default(false);
            $table->string('strong_hint', 500)->nullable();
            $table->string('came_via', 12)->nullable();
            $table->unsignedBigInteger('referrer_customer_id')->nullable()->index();
            $table->unsignedBigInteger('referrer_id')->nullable()->index();
            $table->timestamp('name_checked_at')->nullable();
        });
        Schema::create('referrers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('note', 1000)->nullable();
            $table->timestamps();
        });
        Schema::create('referral_rewards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->unique()->constrained('sales_leads')->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('referrer_customer_id')->nullable()->index();
            $table->unsignedBigInteger('referrer_id')->nullable()->index();
            $table->string('kind', 12); // gift_month | commission
            $table->unsignedInteger('amount')->nullable();
            $table->string('status', 10)->default('waiting'); // waiting | earned | paid | void
            $table->timestamp('earned_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('gift_payment_id')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
        Schema::dropIfExists('referrers');
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropIndex(['referrer_customer_id']);
            $table->dropIndex(['referrer_id']);
            $table->dropColumn(['is_strong', 'strong_hint', 'came_via', 'referrer_customer_id', 'referrer_id', 'name_checked_at']);
        });
    }
};

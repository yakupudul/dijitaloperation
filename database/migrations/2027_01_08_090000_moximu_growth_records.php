<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moximu büyüme sistemi, 1. sürüm (yakup 2026-11-07 "Kararı onaylıyorum"): the sales record is a generic customer
 * record, never a clinic. `clinic` becomes `company` (İşletme); every record gets a Tür (`kind`, from the editable
 * list in agency_settings.lead_kinds), a Sektör (`sector_id`, the catalog the brands already use), a lifecycle
 * (aday → müşteri → eski müşteri; ilgisiz for chats that were never a prospect) and Claude's classification.
 * Money lives in two new layers: sales_opportunities (what we are trying to sell to a record, the first sale and every
 * upsell after it) and agreements (what a brand pays: aylık / dönemlik / tek seferlik, with the monthly payment ticks).
 * Existing customers with a monthly fee get a monthly agreement on their first brand so nothing has to be retyped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->renameColumn('clinic', 'company');
        });
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->string('kind', 30)->nullable()->index();
            $table->unsignedBigInteger('sector_id')->nullable()->index();
            $table->json('classification')->nullable();
            $table->string('lifecycle', 20)->default('prospect')->index();
        });
        DB::table('sales_leads')->where('stage', 'won')->update(['lifecycle' => 'customer']);

        Schema::create('sales_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lead_id')->constrained('sales_leads')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('service', 30)->nullable();
            $table->unsignedInteger('amount')->nullable();
            $table->string('billing', 20)->default('monthly');
            $table->string('status', 20)->default('open')->index();
            $table->unsignedBigInteger('agreement_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->string('lost_reason', 40)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('agreements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->string('kind', 20)->default('monthly');
            $table->unsignedInteger('fee')->default(0);
            $table->unsignedSmallInteger('term_months')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedTinyInteger('due_day')->default(1);
            $table->json('services')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('agreement_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agreement_id')->constrained('agreements')->cascadeOnDelete();
            $table->string('period', 7);
            $table->unsignedInteger('amount')->default(0);
            $table->date('due_on');
            $table->date('paid_on')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['agreement_id', 'period'], 'agreement_payment_period');
        });

        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->json('lead_kinds')->nullable();
        });

        $this->seedAgreements();
    }

    /** Every active customer with a monthly fee and a brand gets one monthly agreement on its first brand (editable). */
    private function seedAgreements(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        $customers = DB::table('customers')->whereNull('deleted_at')->where('status', 'active')->where('monthly_fee', '>', 0)
            ->get(['id', 'monthly_fee', 'service_started_at', 'services']);
        foreach ($customers as $customer) {
            $brandId = DB::table('brands')->where('customer_id', $customer->id)->whereNull('deleted_at')->orderBy('id')->value('id');
            if ($brandId === null || DB::table('agreements')->where('brand_id', $brandId)->exists()) {
                continue;
            }
            $services = json_decode((string) $customer->services, true);
            DB::table('agreements')->insert([
                'brand_id' => $brandId, 'customer_id' => $customer->id, 'kind' => 'monthly', 'fee' => (int) round((float) $customer->monthly_fee),
                'starts_on' => $customer->service_started_at ?: now('Europe/Istanbul')->startOfMonth()->toDateString(), 'due_day' => 1,
                'services' => is_array($services) && $services !== [] ? json_encode(array_values($services)) : null, 'status' => 'active',
                'note' => 'Müşteri kaydındaki aylık ücretten aktarıldı; türünü ve ödeme gününü kontrol et.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->dropColumn('lead_kinds');
        });
        Schema::dropIfExists('agreement_payments');
        Schema::dropIfExists('agreements');
        Schema::dropIfExists('sales_opportunities');
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropIndex(['kind']);
            $table->dropIndex(['sector_id']);
            $table->dropIndex(['lifecycle']);
        });
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'sector_id', 'classification', 'lifecycle']);
        });
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->renameColumn('company', 'clinic');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fatura bilgileri (yakup 2026-11-27): the paying side of a customer, the fields an invoice needs. Unvan stays in
 * legal_name; this adds the vergi no, vergi dairesi, fatura adresi, the e-posta the invoice goes to, whether the
 * customer is an e-Fatura mükellefi, and the vergi levhası file kept on the private disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers') || Schema::hasColumn('customers', 'tax_no')) {
            return;
        }
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('tax_no', 11)->nullable();
            $table->string('tax_office', 120)->nullable();
            $table->text('billing_address')->nullable();
            $table->string('billing_city', 120)->nullable();
            $table->string('billing_email', 180)->nullable();
            $table->string('invoice_kind', 16)->nullable();
            $table->string('tax_certificate_path')->nullable();
            $table->string('billing_source', 40)->nullable();
            $table->timestamp('billing_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'tax_no')) {
            Schema::table('customers', fn (Blueprint $table) => $table->dropColumn([
                'tax_no', 'tax_office', 'billing_address', 'billing_city', 'billing_email', 'invoice_kind', 'tax_certificate_path', 'billing_source', 'billing_updated_at',
            ]));
        }
    }
};

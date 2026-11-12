<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finans (yakup 2026-11-12: "işimle alakalı ödeme konularının takibi"): the agreements become the one place every
 * paid job lives. A job may have no brand (a site built outside the panel), may be paid on dates the operator types
 * (taksit), may be paid in part, and may renew once a year (alan adı, sunucu). Invoices, expense documents and payment
 * receipts are kept as files in finance_documents, whether uploaded, read from Gmail or sent over WhatsApp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agreements', function (Blueprint $table): void {
            $table->unsignedBigInteger('brand_id')->nullable()->change();
            // What the job is called when the brand's name does not say it ("Web sitesi", "Alan adı yenileme").
            $table->string('title', 160)->nullable();
            // Who pays when there is no brand in the panel.
            $table->string('party', 160)->nullable();
            $table->string('party_phone', 30)->nullable();
            // Vergi / TC kimlik no of the payer: an invoice read from Gmail finds its agreement by it.
            $table->string('tax_no', 20)->nullable()->index();
            // auto: a row per month (or year) from the fee. schedule: the rows are the dates and amounts the operator typed.
            $table->string('plan', 20)->default('auto');
            // The whole price of a schedule plan (6 ay peşin, a site in two parts); the rows add up to it.
            $table->unsignedInteger('total')->nullable();
            // The day the present fee began: "bu ücretle ne zamandır çalışıyorum".
            $table->date('fee_since')->nullable();
        });
        Schema::table('agreement_payments', function (Blueprint $table): void {
            $table->dropUnique('agreement_payment_period');
            $table->index(['agreement_id', 'period'], 'agreement_payment_period_idx');
            // What came in so far for this row; the row is paid (paid_on) once it reaches the amount.
            $table->unsignedInteger('paid_amount')->default(0);
            $table->string('label', 80)->nullable();
            // The day the phone was told about this row (once on its day, once when it runs late).
            $table->date('reminded_on')->nullable();
            $table->date('late_reminded_on')->nullable();
        });
        Schema::create('finance_documents', function (Blueprint $table): void {
            $table->id();
            // out: an invoice we wrote to a payer. in: an expense document. receipt: a dekont a payer sent.
            $table->string('direction', 10)->index();
            $table->string('source', 20)->default('upload');
            $table->string('status', 20)->default('new')->index();
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->string('sha256', 64)->nullable()->index();
            $table->date('doc_date')->nullable()->index();
            $table->string('doc_no', 80)->nullable();
            $table->string('counterparty', 200)->nullable();
            $table->string('counterparty_tax_no', 20)->nullable();
            $table->string('vendor_key', 120)->nullable()->index();
            // Kuruş-free: amounts are kept as written on the document, two decimals.
            $table->decimal('amount', 14, 2)->nullable();
            $table->decimal('vat', 14, 2)->nullable();
            $table->string('currency', 3)->default('TRY');
            $table->string('category', 40)->nullable();
            $table->unsignedBigInteger('agreement_id')->nullable()->index();
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->unsignedBigInteger('brand_id')->nullable()->index();
            // An expense paid for a customer (alan adı, sunucu) that is to be asked back from them.
            $table->boolean('rebill')->default(false);
            $table->unsignedBigInteger('rebilled_agreement_id')->nullable();
            $table->string('external_id', 190)->nullable()->index();
            $table->string('note', 500)->nullable();
            $table->json('meta')->nullable();
            // What reading the file with the Claude API cost; counts toward the API's TL limits.
            $table->decimal('cost_usd', 10, 5)->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->json('finance')->nullable();
        });
        Schema::table('whatsapp_messages', function (Blueprint $table): void {
            $table->string('media_id', 190)->nullable();
            $table->string('media_mime', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table): void {
            $table->dropColumn(['media_id', 'media_mime']);
        });
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->dropColumn('finance');
        });
        Schema::dropIfExists('finance_documents');
        Schema::table('agreement_payments', function (Blueprint $table): void {
            $table->dropIndex('agreement_payment_period_idx');
            $table->dropColumn(['paid_amount', 'label', 'reminded_on', 'late_reminded_on']);
        });
        Schema::table('agreements', function (Blueprint $table): void {
            $table->dropIndex(['tax_no']);
        });
        Schema::table('agreements', function (Blueprint $table): void {
            $table->dropColumn(['title', 'party', 'party_phone', 'tax_no', 'plan', 'total', 'fee_since']);
        });
    }
};

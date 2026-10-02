<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 8g: the agency's own lead inbox — requests from the agency's website form (token webhook), and leads added
 * by hand; converted into prospects. Only the agency's leads, never a client's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160)->nullable();
            $table->string('company', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('phone_key', 10)->nullable();
            $table->string('email', 190)->nullable();
            $table->text('message')->nullable();
            $table->string('source', 16)->default('web_form');
            $table->string('page_url', 500)->nullable();
            $table->json('utm')->nullable();
            $table->string('status', 16)->default('new');
            $table->foreignId('prospect_id')->nullable()->constrained('prospects')->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('received_at');
            $table->timestamps();

            $table->index(['status', 'received_at']);
            $table->index('phone_key');
        });

        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->string('lead_inbox_token_hash', 64)->nullable();
            $table->string('lead_inbox_token_hint', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->dropColumn(['lead_inbox_token_hash', 'lead_inbox_token_hint']);
        });
        Schema::dropIfExists('agency_leads');
    }
};

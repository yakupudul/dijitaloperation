<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reklam detayı: the structure of the instant forms the account's ads open (name, intro, questions, thank-you screen),
 * read with the creative snapshot. Never the people who filled them in: no lead content is read or stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('meta_lead_forms')) {
            return;
        }
        Schema::create('meta_lead_forms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('external_resource_id')->nullable();
            $table->string('account_id', 64);
            $table->string('form_id', 64);
            $table->string('name', 300)->nullable();
            $table->string('status', 32)->nullable();
            $table->string('locale', 16)->nullable();
            $table->json('intro')->nullable();
            $table->json('questions')->nullable();
            $table->json('thank_you')->nullable();
            $table->string('privacy_policy_url', 1000)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'form_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_lead_forms');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service Brain, phase 5: methods — "pages that succeed at this service have X" — found by comparing the top and
 * bottom of a cohort (hypothesis), then proven or retired by measuring the recommendations that applied them
 * against similar pages that did not (validated / retired).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brain_methods', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('service_id')->nullable()->index();
            $t->string('page_type', 16)->nullable();
            $t->string('channel', 32)->default('website');
            $t->string('feature', 64);
            $t->string('label', 300);
            $t->decimal('threshold', 12, 3)->nullable();
            $t->string('status', 16)->default('hypothesis');
            $t->json('evidence')->nullable();
            $t->json('outcome')->nullable();
            $t->timestamp('discovered_at')->nullable();
            $t->timestamp('validated_at')->nullable();
            $t->timestamps();
            $t->unique(['service_id', 'page_type', 'channel', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brain_methods');
    }
};

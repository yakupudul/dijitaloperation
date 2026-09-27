<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Application errors grouped by fingerprint (exception class + file:line + normalized message) with occurrence
 * count, first/last seen and the release SHA (storage/app/release.json). Written by ErrorAlertReporter, throttled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_error_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 40)->unique();
            $table->string('exception_class', 255);
            $table->string('location', 255);
            $table->string('message', 500);
            $table->unsignedBigInteger('occurrences')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->string('first_release', 40)->nullable();
            $table->string('last_release', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_error_groups');
    }
};

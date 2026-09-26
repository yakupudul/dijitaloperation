<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Komuta merkezi: snooze for items whose source has no snooze of its own (alerts, brain recs, compliance, leads…). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_snoozes', function (Blueprint $table): void {
            $table->id();
            $table->string('item_key', 96)->unique();
            $table->timestampTz('snoozed_until');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_snoozes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Komuta merkezi alert aging: since when each item's condition has been continuously open (first_seen_at), when it
 * was last seen, a fingerprint of its key numbers (a material change resurfaces it) and when it disappeared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_item_states', function (Blueprint $table): void {
            $table->id();
            $table->string('item_key', 96)->unique();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->string('fingerprint', 64);
            $table->timestampTz('gone_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_item_states');
    }
};

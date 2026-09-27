<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A condition that comes back reopens its one alert row; these columns remember how many times it opened and when
 * it was first seen, so the bell can say "3. kez · ilk 24 Eyl" instead of adding a new row every time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operational_alerts', function (Blueprint $table): void {
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_opened_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('operational_alerts', function (Blueprint $table): void {
            $table->dropColumn(['occurrence_count', 'first_opened_at']);
        });
    }
};

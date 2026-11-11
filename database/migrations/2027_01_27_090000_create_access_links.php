<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bağlantı Düzeni 4.1: one access link per brand (which accesses are asked, what the customer marked as given) and the agency identities the customer adds. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('access_links')) {
            Schema::create('access_links', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('brand_id')->unique()->constrained('brands')->cascadeOnDelete();
                $table->string('token', 48)->unique();
                $table->json('asked');
                $table->json('done')->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
        if (Schema::hasTable('agency_settings') && ! Schema::hasColumn('agency_settings', 'access_identities')) {
            Schema::table('agency_settings', fn (Blueprint $table) => $table->json('access_identities')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('access_links');
        if (Schema::hasTable('agency_settings') && Schema::hasColumn('agency_settings', 'access_identities')) {
            Schema::table('agency_settings', fn (Blueprint $table) => $table->dropColumn('access_identities'));
        }
    }
};

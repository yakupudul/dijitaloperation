<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('digital_assets', function (Blueprint $table) {
            $table->string('domain')->nullable()->after('module_id');
            $table->string('primary_url')->nullable()->after('domain');
            $table->string('cms')->nullable()->after('primary_url');
            $table->json('languages')->nullable()->after('cms');
            $table->json('target_countries')->nullable()->after('languages');
            $table->string('site_type')->nullable()->after('target_countries');
            $table->text('hosting_context')->nullable()->after('site_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('digital_assets', function (Blueprint $table) {
            $table->dropColumn([
                'domain',
                'primary_url',
                'cms',
                'languages',
                'target_countries',
                'site_type',
                'hosting_context',
            ]);
        });
    }
};

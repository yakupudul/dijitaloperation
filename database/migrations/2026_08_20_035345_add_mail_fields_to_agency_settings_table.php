<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->boolean('mail_enabled')->default(false)->after('favicon_path');
            $table->string('mail_from_name')->nullable()->after('mail_enabled');
            $table->string('mail_from_address')->nullable()->after('mail_from_name');
            $table->string('mail_host')->nullable()->after('mail_from_address');
            $table->unsignedSmallInteger('mail_port')->nullable()->after('mail_host');
            $table->string('mail_username')->nullable()->after('mail_port');
            $table->string('mail_encryption', 16)->nullable()->after('mail_username');
            $table->text('mail_password')->nullable()->after('mail_encryption');
        });
    }

    public function down(): void
    {
        Schema::table('agency_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'mail_enabled',
                'mail_from_name',
                'mail_from_address',
                'mail_host',
                'mail_port',
                'mail_username',
                'mail_encryption',
                'mail_password',
            ]);
        });
    }
};

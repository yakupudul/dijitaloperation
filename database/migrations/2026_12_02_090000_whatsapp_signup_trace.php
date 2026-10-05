<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the connect page saw during a WhatsApp signup (popup opened, Facebook's answer, Meta events, failed requests),
 * so an attempt that never returned can be told apart from one that was never started.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_signup_attempts', function (Blueprint $table): void {
            if (! Schema::hasColumn('whatsapp_signup_attempts', 'launched_at')) {
                $table->timestamp('launched_at')->nullable();
            }
            if (! Schema::hasColumn('whatsapp_signup_attempts', 'trace')) {
                $table->json('trace')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_signup_attempts', function (Blueprint $table): void {
            foreach (['launched_at', 'trace'] as $column) {
                if (Schema::hasColumn('whatsapp_signup_attempts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

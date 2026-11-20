<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('panel_user_id')->nullable()->constrained('panel_users')->nullOnDelete();
            $table->string('subject', 190);
            $table->string('category', 30)->default('question');
            $table->string('status', 20)->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'last_message_at']);
            $table->index(['brand_id', 'status']);
        });

        Schema::create('panel_ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('panel_tickets')->cascadeOnDelete();
            $table->foreignId('panel_user_id')->nullable()->constrained('panel_users')->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name', 190)->nullable();
            $table->timestamps();
        });

        Schema::create('panel_announcements', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 190);
            $table->text('body');
            $table->string('link_url', 500)->nullable();
            $table->foreignId('brand_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sector_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'brand_id']);
        });

        Schema::create('panel_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('panel_user_id')->constrained('panel_users')->cascadeOnDelete();
            $table->string('type', 30);
            $table->string('title', 190);
            $table->string('url', 500)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['panel_user_id', 'brand_id', 'read_at']);
        });

        Schema::table('access_roles', function (Blueprint $table): void {
            $table->json('seen_keys')->nullable();
        });
        Schema::table('panel_memberships', function (Blueprint $table): void {
            $table->json('overrides')->nullable();
        });
        Schema::table('panel_requests', function (Blueprint $table): void {
            $table->timestamp('handled_at')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('panel_users')->nullOnDelete();
        });
        Schema::table('panel_brand_settings', function (Blueprint $table): void {
            $table->json('modules')->nullable();
            $table->boolean('is_read_only')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('panel_brand_settings', function (Blueprint $table): void {
            $table->dropColumn(['modules', 'is_read_only']);
        });
        Schema::table('panel_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('handled_by');
            $table->dropColumn('handled_at');
        });
        Schema::table('panel_memberships', function (Blueprint $table): void {
            $table->dropColumn('overrides');
        });
        Schema::table('access_roles', function (Blueprint $table): void {
            $table->dropColumn('seen_keys');
        });
        foreach (['panel_notifications', 'panel_announcements', 'panel_ticket_messages', 'panel_tickets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

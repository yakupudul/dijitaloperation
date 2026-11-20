<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Müşteri paneli, Sürüm 1 (yakup 2026-11-20 "doğruysa beklemeden yap"): central roles (MoxDOP + panel scopes), the
 * brands' own users, and the people / requests / timeline a brand keeps. Every brand-owned table carries brand_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 16);
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('permissions');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['scope', 'name']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('access_role_id')->nullable()->constrained('access_roles')->nullOnDelete();
        });

        Schema::create('panel_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->string('locale', 8)->default('tr');
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('panel_brand_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->string('form_token', 64)->unique();
            $table->timestamps();
        });

        Schema::create('panel_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('panel_user_id')->constrained('panel_users')->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_role_id')->nullable()->constrained('access_roles')->nullOnDelete();
            $table->boolean('is_manager')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('preferences')->nullable();
            $table->timestamps();
            $table->unique(['panel_user_id', 'brand_id']);
        });

        Schema::create('panel_people', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('phone_key', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('gender', 16)->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->string('stage', 32)->default('new');
            $table->string('lost_reason', 32)->nullable();
            $table->string('source', 32)->nullable();
            $table->string('interest')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('panel_users')->nullOnDelete();
            $table->timestamp('appointment_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->unique(['brand_id', 'phone_key']);
            $table->index(['brand_id', 'stage']);
            $table->index(['brand_id', 'last_activity_at']);
        });

        Schema::create('panel_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('panel_people')->cascadeOnDelete();
            $table->string('source', 32);
            $table->string('interest')->nullable();
            $table->text('message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();
            $table->index(['brand_id', 'received_at']);
            $table->index(['brand_id', 'source']);
        });

        Schema::create('panel_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('panel_people')->cascadeOnDelete();
            $table->string('type', 32);
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('panel_user_id')->nullable()->constrained('panel_users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['brand_id', 'person_id', 'occurred_at']);
        });

        Schema::create('panel_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('panel_people')->cascadeOnDelete();
            $table->foreignId('panel_user_id')->nullable()->constrained('panel_users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('panel_users')->nullOnDelete();
            $table->string('title');
            $table->timestamp('due_at');
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->index(['brand_id', 'done_at', 'due_at']);
        });

        Schema::create('panel_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('panel_people')->cascadeOnDelete();
            $table->foreignId('panel_user_id')->nullable()->constrained('panel_users')->nullOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });

        Schema::create('panel_audit', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 48);
            $table->json('meta')->nullable();
            $table->string('ip', 64)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['brand_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['panel_audit', 'panel_files', 'panel_reminders', 'panel_events', 'panel_requests', 'panel_people', 'panel_memberships', 'panel_brand_settings', 'panel_users'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('access_role_id');
        });
        Schema::dropIfExists('access_roles');
    }
};

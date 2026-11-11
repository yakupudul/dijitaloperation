<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Bağlantı Düzeni 2.5: who changed which setting when, with the value before so one tap puts it back. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('setting_changes')) {
            return;
        }
        Schema::create('setting_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target', 191)->index(); // which setting, for the undo (agency:<column>, level:<brand>:<channel>, job:<key>, sales:rules, sales:prices)
            $table->string('label', 191);
            $table->text('before')->nullable(); // encrypted JSON
            $table->text('after')->nullable(); // encrypted JSON
            $table->boolean('secret')->default(false); // a key or password changed: values are not kept, no undo
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_changes');
    }
};

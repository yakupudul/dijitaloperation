<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Günlük Düzen 4.2 (söz defteri): a promise caught in the operator's own outgoing message, asked once, then a task or dismissed. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('promises')) {
            Schema::create('promises', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('message_id')->unique();
                $table->unsignedBigInteger('conversation_id')->nullable()->index();
                $table->unsignedBigInteger('sales_lead_id')->nullable();
                $table->unsignedBigInteger('reminder_id')->nullable();
                $table->string('who', 160)->nullable();
                $table->text('text');
                $table->date('due_on');
                $table->string('status', 12)->default('asked')->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('promises');
    }
};

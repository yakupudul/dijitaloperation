<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_route_steps', function (Blueprint $table): void {
            $table->id();
            $table->string('route_key', 100);
            $table->string('provider', 50);
            $table->string('model', 191);
            $table->unsignedSmallInteger('position');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['route_key', 'provider']);
            $table->unique(['route_key', 'position']);
            $table->index('route_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_route_steps');
    }
};

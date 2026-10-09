<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Connector 1.13.0: requests the site fetches from MoxDOP itself when its host refuses MoxDOP's requests. */
    public function up(): void
    {
        Schema::create('website_connector_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('core_connections')->cascadeOnDelete();
            $table->string('method', 8);
            $table->string('route', 191);
            $table->json('query')->nullable();
            $table->longText('body')->nullable();
            $table->string('status', 16)->default('pending');
            $table->uuid('nonce')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->string('error', 300)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->index(['connection_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_connector_commands');
    }
};

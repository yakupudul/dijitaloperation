<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            if (! Schema::hasColumn('customers', 'industry')) {
                $table->string('industry')->nullable()->after('status');
            }
            if (! Schema::hasColumn('customers', 'hq_country')) {
                $table->string('hq_country', 2)->nullable()->after('industry');
            }
            if (! Schema::hasColumn('customers', 'hq_city')) {
                $table->string('hq_city')->nullable()->after('hq_country');
            }
            if (! Schema::hasColumn('customers', 'services')) {
                $table->json('services')->nullable()->after('services_received');
            }
        });

        if (! Schema::hasTable('customer_user')) {
            Schema::create('customer_user', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['customer_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_user');

        Schema::table('customers', function (Blueprint $table): void {
            foreach (['industry', 'hq_country', 'hq_city', 'services'] as $column) {
                if (Schema::hasColumn('customers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

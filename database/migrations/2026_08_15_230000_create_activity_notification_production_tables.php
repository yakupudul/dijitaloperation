<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 47 — durable Domain Events, Activity projection key, in-app Notifications, preferences.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('domain_events')) {
            Schema::create('domain_events', function (Blueprint $table): void {
                $table->id();
                $table->string('event_type', 64);
                $table->string('idempotency_key')->unique();
                $table->string('actor_kind', 32);
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('digital_asset_id')->nullable()->constrained()->nullOnDelete();
                $table->string('subject_kind', 64);
                $table->unsignedBigInteger('subject_id');
                $table->json('payload')->nullable();
                $table->string('correlation_id', 64)->nullable();
                $table->unsignedBigInteger('causation_event_id')->nullable();
                $table->timestamp('occurred_at');
                $table->string('projection_status', 32)->default('projected');
                $table->timestamps();

                $table->index(['event_type', 'occurred_at'], 'domain_events_type_occurred_index');
                $table->index(['subject_kind', 'subject_id'], 'domain_events_subject_index');
                $table->index(['customer_id', 'occurred_at'], 'domain_events_customer_occurred_index');
                $table->index(['brand_id', 'occurred_at'], 'domain_events_brand_occurred_index');
                $table->index(['projection_status'], 'domain_events_projection_status_index');
            });
        }

        if (Schema::hasTable('brand_context_activities')) {
            Schema::table('brand_context_activities', function (Blueprint $table): void {
                if (! Schema::hasColumn('brand_context_activities', 'domain_event_id')) {
                    $table->foreignId('domain_event_id')
                        ->nullable()
                        ->after('id')
                        ->constrained('domain_events')
                        ->nullOnDelete();
                }
                if (! Schema::hasColumn('brand_context_activities', 'customer_id')) {
                    $table->foreignId('customer_id')->nullable()->after('brand_id')->constrained()->nullOnDelete();
                }
                if (! Schema::hasColumn('brand_context_activities', 'digital_asset_id')) {
                    $table->foreignId('digital_asset_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
                }
                if (! Schema::hasColumn('brand_context_activities', 'actor_kind')) {
                    $table->string('actor_kind', 32)->nullable()->after('actor_user_id');
                }
                if (! Schema::hasColumn('brand_context_activities', 'occurred_at')) {
                    $table->timestamp('occurred_at')->nullable()->after('payload');
                }
            });

            if (! $this->hasIndexNamed('brand_context_activities', 'bca_domain_event_unique')) {
                Schema::table('brand_context_activities', function (Blueprint $table): void {
                    $table->unique('domain_event_id', 'bca_domain_event_unique');
                });
            }
            if (! $this->hasIndexNamed('brand_context_activities', 'bca_customer_created_index')) {
                Schema::table('brand_context_activities', function (Blueprint $table): void {
                    $table->index(['customer_id', 'created_at'], 'bca_customer_created_index');
                });
            }
            if (! $this->hasIndexNamed('brand_context_activities', 'bca_asset_created_index')) {
                Schema::table('brand_context_activities', function (Blueprint $table): void {
                    $table->index(['digital_asset_id', 'created_at'], 'bca_asset_created_index');
                });
            }
        }

        if (! Schema::hasTable('user_notifications')) {
            Schema::create('user_notifications', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('domain_event_id')->constrained('domain_events')->cascadeOnDelete();
                $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('notification_kind', 64);
                $table->string('subject_kind', 64);
                $table->unsignedBigInteger('subject_id');
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
                $table->json('presentation')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['domain_event_id', 'recipient_user_id', 'notification_kind'],
                    'user_notifications_event_recipient_kind_unique'
                );
                $table->index(['recipient_user_id', 'read_at', 'created_at'], 'user_notifications_recipient_unread_index');
                $table->index(['recipient_user_id', 'archived_at', 'created_at'], 'user_notifications_recipient_list_index');
                $table->index(['subject_kind', 'subject_id'], 'user_notifications_subject_index');
            });
        }

        if (! Schema::hasTable('notification_preferences')) {
            Schema::create('notification_preferences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('preference_key', 64);
                $table->boolean('in_app_enabled')->default(true);
                $table->boolean('email_enabled')->default(false);
                $table->timestamps();

                $table->unique(['user_id', 'preference_key'], 'notification_preferences_user_key_unique');
            });
        }

        $this->addPostgresChecks();
    }

    public function down(): void
    {
        $this->dropPostgresChecks();
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('user_notifications');

        if (Schema::hasTable('brand_context_activities')) {
            foreach (['bca_domain_event_unique', 'bca_customer_created_index', 'bca_asset_created_index'] as $indexName) {
                if (! $this->hasIndexNamed('brand_context_activities', $indexName)) {
                    continue;
                }
                if (Schema::getConnection()->getDriverName() === 'sqlite') {
                    DB::statement('DROP INDEX IF EXISTS "'.$indexName.'"');
                } else {
                    Schema::table('brand_context_activities', function (Blueprint $table) use ($indexName): void {
                        if ($indexName === 'bca_domain_event_unique') {
                            $table->dropUnique($indexName);
                        } else {
                            $table->dropIndex($indexName);
                        }
                    });
                }
            }

            Schema::table('brand_context_activities', function (Blueprint $table): void {
                $columns = [];
                foreach (['domain_event_id', 'customer_id', 'digital_asset_id', 'actor_kind', 'occurred_at'] as $column) {
                    if (Schema::hasColumn('brand_context_activities', $column)) {
                        $columns[] = $column;
                    }
                }
                if ($columns !== []) {
                    if (in_array('domain_event_id', $columns, true)) {
                        try {
                            $table->dropForeign(['domain_event_id']);
                        } catch (Throwable) {
                        }
                    }
                    if (in_array('customer_id', $columns, true)) {
                        try {
                            $table->dropForeign(['customer_id']);
                        } catch (Throwable) {
                        }
                    }
                    if (in_array('digital_asset_id', $columns, true)) {
                        try {
                            $table->dropForeign(['digital_asset_id']);
                        } catch (Throwable) {
                        }
                    }
                    $table->dropColumn($columns);
                }
            });
        }

        Schema::dropIfExists('domain_events');
    }

    private function addPostgresChecks(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('
            ALTER TABLE domain_events
            DROP CONSTRAINT IF EXISTS domain_events_actor_kind_check,
            DROP CONSTRAINT IF EXISTS domain_events_projection_status_check
        ');
        DB::statement("
            ALTER TABLE domain_events
            ADD CONSTRAINT domain_events_actor_kind_check CHECK (actor_kind IN ('internal_user', 'system', 'client_contact'))
        ");
        DB::statement("
            ALTER TABLE domain_events
            ADD CONSTRAINT domain_events_projection_status_check CHECK (projection_status IN ('pending', 'projected', 'failed'))
        ");
    }

    private function dropPostgresChecks(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE domain_events DROP CONSTRAINT IF EXISTS domain_events_actor_kind_check');
        DB::statement('ALTER TABLE domain_events DROP CONSTRAINT IF EXISTS domain_events_projection_status_check');
    }

    private function hasIndexNamed(string $table, string $name): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            $rows = DB::select("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?", [$table, $name]);

            return $rows !== [];
        }
        if ($driver === 'pgsql') {
            $rows = DB::select('SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?', [$table, $name]);

            return $rows !== [];
        }

        return Schema::hasIndex($table, $name);
    }
};

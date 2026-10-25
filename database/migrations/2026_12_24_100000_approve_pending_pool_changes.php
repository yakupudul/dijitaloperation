<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * yakup approved every waiting card of the Geliştirme havuzu on 2026-10-25 21:24 (Istanbul) in the project chat ("Benim
 * onayımı bekleyen tüm maddeleri onaylıyorum"), the system debt plan included (cards #57 and #58 written for its last
 * phase that evening). From then on errors go in approved by themselves (SystemChangeDesk).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_changes')) {
            return;
        }
        $admin = Schema::hasTable('users') ? DB::table('users')->where('is_active', true)->orderBy('id')->value('id') : null;
        DB::table('system_changes')->where('status', 'proposed')->where('created_at', '<=', '2026-10-25 20:00:00')->update([
            'status' => 'approved', 'decided_at' => now(), 'decided_by' => $admin,
            'operator_note' => DB::raw("coalesce(operator_note, 'yakup 2026-10-25 21:24 sohbette hepsini onayladı.')"),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // The approval is the operator's decision; it is not taken back.
    }
};

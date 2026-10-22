<?php

use App\Enums\DigitalAssetStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Panel audit, first round 10: a website record without a name, an address or a brand (connector 1.0.0) sat in the
 * WordPress lists and checks. It becomes passive; deleting it needs yakup's approval, so nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('digital_assets')) {
            return;
        }
        $blank = fn ($q, string $column) => $q->where(fn ($inner) => $inner->whereNull($column)->orWhere($column, ''));
        $query = DB::table('digital_assets')->where('type', 'website')->whereNull('brand_id')->where('status', DigitalAssetStatus::Active->value);
        foreach (['name', 'primary_url', 'domain'] as $column) {
            $blank($query, $column);
        }
        $query->update(['status' => DigitalAssetStatus::Inactive->value, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Data fix: the record is switched back on from Web siteleri if it was ever needed.
    }
};

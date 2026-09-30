<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MoxDOP v2 — web sitesi ekranı düzeltmeleri.
 *
 * 1. brand_cluster_pages.extra_page_ids: a cluster keeps one target URL, the operator may add more (json list of page ids).
 * 2. backlink_sources.status: exactly five values — yok · basvuru · verildi · dogrulandi · kaldirildi. A source the verifier
 *    had sent back to "yok" with the note "Bağlantı kaldırıldı" becomes kaldirildi.
 * 3. backlinks.source: the unwired 'dataforseo' value is gone (such rows become manual).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('brand_cluster_pages', 'extra_page_ids')) {
            Schema::table('brand_cluster_pages', fn (Blueprint $table) => $table->json('extra_page_ids')->nullable());
        }
        DB::table('backlink_sources')->where('status', 'yok')->where('note', 'like', 'Bağlantı kaldırıldı%')->update(['status' => 'kaldirildi']);
        DB::table('backlink_sources')->whereNotIn('status', ['yok', 'basvuru', 'verildi', 'dogrulandi', 'kaldirildi'])->update(['status' => 'yok']);
        DB::table('backlinks')->where('source', 'dataforseo')->update(['source' => 'manual']);
    }

    public function down(): void
    {
        DB::table('backlink_sources')->where('status', 'kaldirildi')->update(['status' => 'yok']);
        DB::table('backlink_sources')->where('status', 'basvuru')->update(['status' => 'yok']);
        if (Schema::hasColumn('brand_cluster_pages', 'extra_page_ids')) {
            Schema::table('brand_cluster_pages', fn (Blueprint $table) => $table->dropColumn('extra_page_ids'));
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pages.content_outline: the main content as a light Markdown outline ("## Başlık", paragraphs, "- madde", tables,
 * "S: / C:" for questions) — what AI operations read instead of flat text. Not part of the content hash; no HTML.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pages') && ! Schema::hasColumn('pages', 'content_outline')) {
            Schema::table('pages', function (Blueprint $table): void {
                $table->longText('content_outline')->nullable()->after('content_text');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pages', 'content_outline')) {
            Schema::table('pages', function (Blueprint $table): void {
                $table->dropColumn('content_outline');
            });
        }
    }
};

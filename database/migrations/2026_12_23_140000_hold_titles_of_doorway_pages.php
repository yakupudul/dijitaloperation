<?php

use App\Models\DigitalAsset;
use App\Services\Site\DoorwayCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Bornova Hurda (2026-10-22): title rows naming Müge Anlı, Hurdacı Rasim, Çolakoğlu, Akyol Hurdacılık reached the desk
 * before "Hepsini gönder". Every prepared title / description of a doorway set's page is held back now (kept under
 * held_proposal, given back once Claude clears the page); nothing is sent or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suggestions') || ! Schema::hasTable('pages')) {
            return;
        }
        DigitalAsset::query()->where('type', 'website')->whereNotNull('brand_id')->orderBy('id')
            ->each(fn (DigitalAsset $site) => app(DoorwayCheck::class)->hold($site));
    }

    public function down(): void {}
};

<?php

namespace App\Services\BrandSetup;

use App\Jobs\BuildBrandSetupProposalJob;
use App\Models\Brand;
use App\Models\BrandSetupProposal;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\User;
use App\Services\Portfolio\UnassignedWebsites;
use App\Support\ServiceScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Orchestrates "Otomatik kur": queue → build (matcher + service suggester + area suggester, each step recorded so the
 * page shows where the build is) → operator approval. Opening the page again never starts a new build: only
 * "Önerileri hazırla" / "Yeniden tara" does, and while one is running the same one is followed.
 */
final class BrandSetupAssistant
{
    /** Steps the page shows while a proposal is being built (key => label). */
    public const array STEPS = [
        'queued' => 'Sırada (arka plan işçisi bekleniyor)',
        'accounts' => 'Hesaplar alan adıyla eşleştiriliyor (Search Console, GA4, İşletme Profili, reklam hesapları)',
        'services' => 'Sayfalar ve sorgular okunuyor, AI hizmetleri çıkarıyor',
        'areas' => 'Hizmet bölgeleri bulunuyor',
    ];

    public function __construct(
        private readonly BrandSetupMatcher $matcher,
        private readonly BrandSetupServiceSuggester $services,
        private readonly BrandSetupAreaSuggester $areas,
    ) {}

    public static function progressKey(int $proposalId): string
    {
        return 'brand-setup:progress:'.$proposalId;
    }

    /** @return array{step: string, at: string}|null */
    public static function progress(int $proposalId): ?array
    {
        $progress = Cache::get(self::progressKey($proposalId));

        return is_array($progress) ? $progress : null;
    }

    /** Records the current step and keeps the proposal fresh (a long build is not "stuck"). */
    private static function step(BrandSetupProposal $proposal, string $step): void
    {
        Cache::put(self::progressKey((int) $proposal->id), ['step' => $step, 'at' => now()->toIso8601String()], now()->addHour());
        $proposal->forceFill(['updated_at' => now()])->save();
    }

    /**
     * What keeps "Otomatik kur" from reading the site (operator decision 2026-11-18: a job that cannot work properly
     * warns first and runs only when the operator says "Yine de getir"): no website asset for the address, or a site
     * whose pages were never collected. Empty: ready.
     *
     * @return list<string>
     */
    public function readiness(Brand $brand, string $websiteUrl): array
    {
        $host = BrandSetupMatcher::host($websiteUrl);
        if ($host === '' || ! str_contains($host, '.')) {
            return [];
        }
        $site = $brand->digitalAssets()->where('type', 'website')->get()
            ->first(fn (DigitalAsset $asset): bool => BrandSetupMatcher::host((string) ($asset->primary_url ?: $asset->domain)) === $host)
            ?? app(UnassignedWebsites::class)->findByHost($host);
        if ($site === null) {
            return ['Bu adres için bağlı bir web sitesi yok. Hizmetler siteden okunamaz; yalnız arama verisinden (varsa) tahmin edilir ve eksik kalır. Önce siteyi Dijital varlıklara ekleyip sayfalarının toplanmasını bekleyin.'];
        }
        $pages = Page::query()->where('website_asset_id', $site->id)->count();
        $wordpress = Schema::hasTable('website_cms_object_snapshot') ? DB::table('website_cms_object_snapshot')->where('digital_asset_id', $site->id)->count() : 0;
        if ($pages === 0 && $wordpress === 0) {
            return ['Sitenin sayfaları henüz toplanmadı. Hizmetler sayfalardan çıkarılır; şimdi çalışırsa çoğu hizmet bulunamaz. Sitenin Veri kaynakları ekranından toplamayı başlatıp bitmesini bekleyin.'];
        }

        return [];
    }

    public function queue(Brand $brand, string $websiteUrl, ?User $actor): BrandSetupProposal
    {
        app(ServiceScope::class)->ensureBrandServed($brand, 'websiteUrl');
        $host = BrandSetupMatcher::host($websiteUrl);
        if ($host === '' || ! str_contains($host, '.')) {
            throw ValidationException::withMessages(['websiteUrl' => 'Geçerli bir web sitesi adresi girin (ör. ornek.com.tr).']);
        }
        $pending = BrandSetupProposal::query()->where('brand_id', $brand->id)
            ->whereIn('status', [BrandSetupProposal::STATUS_QUEUED, BrandSetupProposal::STATUS_BUILDING])
            ->where('updated_at', '>=', now()->subMinutes(15))->latest('id')->first();
        if ($pending !== null) {
            return $pending;
        }
        // An older run that never finished is closed so the new one is the only one the page follows.
        BrandSetupProposal::query()->where('brand_id', $brand->id)->whereIn('status', [BrandSetupProposal::STATUS_QUEUED, BrandSetupProposal::STATUS_BUILDING])
            ->update(['status' => BrandSetupProposal::STATUS_FAILED, 'error_summary' => 'Zaman aşımı: hazırlık yarım kaldı, yeniden tarandı.', 'updated_at' => now()]);

        $proposal = BrandSetupProposal::query()->create([
            'brand_id' => $brand->id,
            'status' => BrandSetupProposal::STATUS_QUEUED,
            'website_url' => $websiteUrl,
            'created_by' => $actor?->id,
        ]);
        dispatch(new BuildBrandSetupProposalJob($proposal->id))->afterCommit();

        return $proposal;
    }

    public function build(int $proposalId): BrandSetupProposal
    {
        $proposal = BrandSetupProposal::query()->with('brand')->findOrFail($proposalId);
        // Re-checked at handle time: no AI / discovery work for a passive customer's brand.
        if (! app(ServiceScope::class)->isBrandOperational($proposal->brand_id)) {
            $proposal->forceFill(['status' => BrandSetupProposal::STATUS_FAILED, 'error_summary' => ServiceScope::NOT_SERVED])->save();

            return $proposal;
        }
        $proposal->forceFill(['status' => BrandSetupProposal::STATUS_BUILDING])->save();
        try {
            $brand = $proposal->brand;
            self::step($proposal, 'accounts');
            $items = $this->matcher->propose($brand, (string) $proposal->website_url);
            self::step($proposal, 'services');
            $suggestion = $this->services->suggest($brand, BrandSetupMatcher::host((string) $proposal->website_url), $items);
            self::step($proposal, 'areas');
            $summary = $suggestion['summary'] + ['areas' => $this->areas->suggest($brand, $items, (array) ($suggestion['summary']['locations'] ?? []))];
            $proposal->forceFill([
                'status' => BrandSetupProposal::STATUS_READY,
                'items' => $items,
                'services' => $suggestion['services'],
                'services_status' => $suggestion['status'],
                'summary' => $summary,
            ])->save();
            Cache::forget(self::progressKey((int) $proposal->id));
        } catch (Throwable $exception) {
            $proposal->forceFill(['status' => BrandSetupProposal::STATUS_FAILED, 'error_summary' => mb_substr($exception->getMessage(), 0, 500)])->save();

            throw $exception;
        }

        return $proposal;
    }
}

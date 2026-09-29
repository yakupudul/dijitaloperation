<?php

namespace App\Services\BrandSetup;

use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandSetupProposal;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Catalog\ServiceKeywordService;
use App\Services\Integrations\ConfirmGoogleResourceBindingService;
use App\Services\Integrations\ConfirmMetaResourceBindingService;
use App\Services\Ownership\OwnershipGuard;
use App\Services\Portfolio\UnassignedWebsites;
use App\Support\Integrations\ResourceBindingPlan;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Applies the operator-approved part of a setup proposal through the existing, validated services
 * (binding confirmation, offerings, service catalog). Each item succeeds or fails on its own and the
 * outcome is recorded; nothing outside MoxDOP is written.
 *
 * Never throws for a bad proposal row, a database refusal (length, uniqueness) or a failing service: the step is
 * reported as "yapılamadı" with a Turkish reason, the rest continues, and the proposal is marked applied with the
 * per-step outcome. A proposal is applied once (a second click or a parallel request gets a notice).
 *
 * Automatic flow: it never transfers ownership. An account already bound to another asset, or a website whose domain
 * already belongs to another brand, is skipped and reported — the operator can transfer it by hand (Veri kaynakları /
 * varlık düzenleme) with an explicit yetki devri confirmation.
 */
final class BrandSetupApplier
{
    private const array ASSET_LABELS = [
        'google_business_profile' => 'İşletme Profili',
        'google_ads' => 'Google Ads',
        'meta_ads' => 'Meta Ads',
    ];

    /** brand_intelligence_contexts column limits (business_model is varchar(64); the text columns get a sane cap). */
    private const array CONTEXT_TEXT_LIMITS = ['business_summary' => 2000, 'business_model' => 64, 'positioning' => 1000];

    public function __construct(
        private readonly ConfirmGoogleResourceBindingService $google,
        private readonly ConfirmMetaResourceBindingService $meta,
        private readonly BrandOfferingService $offerings,
        private readonly ServiceCatalogService $catalog,
        private readonly OwnershipGuard $ownership,
    ) {}

    /**
     * @param  list<string>  $itemKeys  selected asset/binding item keys
     * @param  list<int>  $serviceIndexes  selected service rows
     * @return list<array{key: string, label: string, ok: bool, message: string}>
     */
    public function apply(BrandSetupProposal $proposal, User $actor, array $itemKeys, array $serviceIndexes, bool $applyContext = true): array
    {
        $brand = $proposal->brand()->first();
        if (! $brand instanceof Brand) {
            return [$this->failed('brand', 'Marka', 'Marka bulunamadı; öneri uygulanmadı.')];
        }
        // Claim the proposal so a double click or a second tab cannot apply it twice.
        $claimed = BrandSetupProposal::query()->whereKey($proposal->id)->where('status', '!=', BrandSetupProposal::STATUS_APPLIED)
            ->update(['status' => BrandSetupProposal::STATUS_APPLIED, 'applied_by' => $actor->id, 'applied_at' => now(), 'updated_at' => now()]);
        if ($claimed === 0) {
            return [$this->failed('proposal', 'Öneri', 'Bu öneri zaten uygulandı; sonuçlar aşağıda.')];
        }

        $results = [];
        try {
            $this->applySteps($proposal, $brand, $actor, $itemKeys, $serviceIndexes, $applyContext, $results);
        } catch (Throwable $exception) {
            // Every step guards itself; this only catches a bug between steps so the operator still gets the list.
            $results[] = $this->failed('apply', 'Uygulama', $this->reason($exception, 'Uygulama yarıda kaldı'));
        } finally {
            $results = array_map(fn (array $row): array => [
                'key' => $this->clean((string) $row['key'], 255),
                'label' => $this->clean((string) $row['label'], 255),
                'ok' => (bool) $row['ok'],
                'message' => $this->clean((string) $row['message'], 600),
            ], $results);
            $proposal->forceFill([
                'status' => BrandSetupProposal::STATUS_APPLIED,
                'apply_result' => $results,
                'applied_by' => $actor->id,
                'applied_at' => now(),
            ])->save();
        }

        return $results;
    }

    /**
     * @param  list<string>  $itemKeys
     * @param  list<int>  $serviceIndexes
     * @param  list<array{key: string, label: string, ok: bool, message: string}>  $results
     */
    private function applySteps(BrandSetupProposal $proposal, Brand $brand, User $actor, array $itemKeys, array $serviceIndexes, bool $applyContext, array &$results): void
    {
        $selected = array_flip(array_values(array_filter($itemKeys, 'is_string')));
        $items = $proposal->itemRows();

        // 1) Website asset.
        $websiteItem = collect($items)->firstWhere('key', 'asset:website');
        $website = null;
        if ($websiteItem !== null) {
            $website = $this->attempt($results, 'asset:website', $websiteItem['label'], 'Web sitesi varlığı kaydedilemedi',
                function () use ($websiteItem, $brand, $selected, &$results): ?DigitalAsset {
                    return $this->website($websiteItem, $brand, isset($selected['asset:website']), $results);
                });
        }

        // 2) Bindings. Accounts owned by another asset are skipped and counted, never moved.
        $skippedOwned = 0;
        foreach ($items as $item) {
            if ($item['kind'] !== 'bind' || ! isset($selected[$item['key']]) || $item['status'] !== 'proposed') {
                continue;
            }
            $outcome = $this->attempt($results, $item['key'], $item['label'], 'Hesap bağlanamadı', fn (): array => $this->bind($item, $brand, $website, $actor));
            if (! is_array($outcome)) {
                continue;
            }
            if (($outcome['owned_elsewhere'] ?? false) === true) {
                $skippedOwned++;
            }
            unset($outcome['owned_elsewhere']);
            $results[] = $outcome;
        }
        if ($skippedOwned > 0) {
            $results[] = $this->failed('ownership', 'Başka varlığa bağlı hesaplar',
                sprintf('%d hesap başka bir varlığa bağlı olduğu için atlandı; hiçbiri taşınmadı. Gerekiyorsa Veri kaynaklarından yetki devriyle devredebilirsiniz.', $skippedOwned));
        }

        // 3) Services and sector. The same service picked twice (duplicate AI rows) is applied once.
        $services = $proposal->serviceRows();
        $keywordCount = 0;
        $seenServices = [];
        foreach (array_unique(array_map('intval', array_filter($serviceIndexes, 'is_numeric'))) as $index) {
            $service = $services[$index] ?? null;
            if ($service === null) {
                continue;
            }
            $serviceKey = mb_strtolower($service['name']);
            if (isset($seenServices[$serviceKey])) {
                continue;
            }
            $seenServices[$serviceKey] = true;
            $results[] = $this->service($service, $brand, $actor, $keywordCount);
        }
        $sectorCode = data_get($proposal->summary, 'sector_code');
        if (is_string($sectorCode) && trim($sectorCode) !== '') {
            $this->attempt($results, 'sector', 'Sektör', 'Sektör atanamadı', function () use ($brand, $sectorCode, &$results): void {
                if ($brand->sector_id !== null) {
                    return;
                }
                $category = ServiceCategory::query()->where('code', mb_substr(trim($sectorCode), 0, 120))->first();
                if ($category === null) {
                    $results[] = $this->failed('sector', 'Sektör', 'Önerilen sektör katalogda yok; sektör atanmadı. Markanın düzenleme sayfasından seçebilirsiniz.');

                    return;
                }
                $brand->forceFill(['sector_id' => $category->id])->save();
                $results[] = ['key' => 'sector', 'label' => 'Sektör: '.$category->name, 'ok' => true, 'message' => 'Markanın sektörü atandı.'];
            });
        }

        // 3b) İş bağlamı: fill the business context from the site, only fields the operator has not written.
        if ($applyContext && is_array($context = data_get($proposal->summary, 'business_context'))) {
            $outcome = $this->attempt($results, 'context', 'İş bağlamı', 'İş bağlamı kaydedilemedi', fn (): array => $this->businessContext($brand, $context, $actor));
            if (is_array($outcome)) {
                $results[] = $outcome;
            }
        }

        // 4) Follow-up: crawl the site when services are still waiting.
        if ($website !== null && $proposal->services_status === 'waiting_for_site') {
            try {
                app(AsyncOperationService::class)->queuePublicDiscovery($website, $actor);
                $results[] = ['key' => 'discovery', 'label' => 'Site taraması', 'ok' => true, 'message' => 'Site taraması kuyruğa alındı; bitince "Otomatik kur" hizmetleri önerebilir.'];
            } catch (Throwable $exception) {
                $results[] = $this->failed('discovery', 'Site taraması', $this->reason($exception, 'Site taraması başlatılamadı'));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $websiteItem
     * @param  list<array{key: string, label: string, ok: bool, message: string}>  $results
     */
    private function website(array $websiteItem, Brand $brand, bool $selected, array &$results): ?DigitalAsset
    {
        $website = $websiteItem['asset_id'] !== null ? DigitalAsset::query()->find($websiteItem['asset_id']) : null;
        if ($website !== null && $website->type === 'website' && ($website->brand_id === null || (int) $website->brand_id === (int) $brand->id)) {
            return $website;
        }
        if (! $selected) {
            return null;
        }
        $host = BrandSetupMatcher::host($websiteItem['url']);
        if ($host === '' || ! str_contains($host, '.') || mb_strlen($host) > 253) {
            $results[] = $this->failed('asset:website', $websiteItem['label'], 'Web sitesi adresi geçersiz; varlık oluşturulmadı. "Yeniden tara" ile doğru adresi girin.');

            return null;
        }
        $existing = $this->ownership->existingWebsite($host);
        if ($existing !== null && $existing->brand_id === null) {
            // A website added under Integrations before its brand: it simply joins this brand (no owner yet).
            app(UnassignedWebsites::class)->assign($existing, $brand);
            $results[] = ['key' => 'asset:website', 'label' => $websiteItem['label'], 'ok' => true, 'message' => 'Markaya bağlı olmayan web sitesi bu markaya alındı.'];

            return $existing->fresh();
        }
        if ($existing !== null && (int) $existing->brand_id !== (int) $brand->id) {
            $owner = collect([$existing->brand?->customer?->name, $existing->brand?->name, $existing->name])->filter()->implode(' › ');
            $results[] = $this->failed('asset:website', $websiteItem['label'],
                sprintf('Bu alan adı zaten kayıtlı (%s); ikinci bir web sitesi oluşturulmadı. Siteyi bu markaya almak için varlığın düzenleme sayfasından müşteri ve markasını değiştirip yetki devrini onaylayın.', $owner));

            return null;
        }
        if ($existing !== null) {
            return $existing;
        }
        $url = mb_strlen($websiteItem['url']) <= 255 && BrandSetupMatcher::host($websiteItem['url']) === $host ? $websiteItem['url'] : 'https://'.$host.'/';
        $website = DigitalAsset::query()->create([
            'brand_id' => $brand->id, 'name' => $host, 'type' => 'website', 'status' => 'active',
            'module_id' => 'website', 'domain' => $host, 'primary_url' => $url,
        ]);
        $results[] = ['key' => 'asset:website', 'label' => $websiteItem['label'], 'ok' => true, 'message' => 'Web sitesi varlığı oluşturuldu.'];

        return $website;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{key: string, label: string, ok: bool, message: string, owned_elsewhere?: bool}
     */
    private function bind(array $item, Brand $brand, ?DigitalAsset $website, User $actor): array
    {
        $result = ['key' => $item['key'], 'label' => $item['label'], 'ok' => false, 'message' => ''];
        $resource = $item['resource_id'] !== null ? CoreExternalResource::query()->find($item['resource_id']) : null;
        if ($resource === null) {
            return array_merge($result, ['message' => 'Hesap artık listede yok; entegrasyonu yenileyin.']);
        }
        $target = $item['target'] === 'website' ? $website : null;
        if ($item['target'] === 'website' && $website === null) {
            return array_merge($result, ['message' => 'Önce web sitesi varlığı gerekiyor.']);
        }
        if ($item['target'] !== 'website' && ! str_starts_with($item['target'], 'new:')) {
            return array_merge($result, ['message' => 'Öneride hedef varlık yok; hesabı Veri kaynaklarından elle bağlayın.']);
        }
        $conflict = $target !== null ? $this->ownership->forResource($resource, $target) : $this->ownership->forResourceInBrand($resource, $brand);
        if ($conflict !== null) {
            return array_merge($result, ['message' => $conflict->skippedMessage(), 'owned_elsewhere' => true]);
        }
        try {
            if ($target !== null) {
                $this->google->bindExisting($target, $resource, $actor, allowReplace: false);

                return array_merge($result, ['ok' => true, 'message' => 'Web sitesine bağlandı.']);
            }

            $type = substr($item['target'], strlen('new:'));
            $plan = new ResourceBindingPlan(
                $resource,
                $brand,
                ResourceBindingPlan::MODE_CREATE_ASSET,
                null,
                mb_substr((self::ASSET_LABELS[$type] ?? $type).' · '.($resource->display_name ?: $resource->external_id), 0, 255),
                $actor,
            );
            $outcome = $resource->provider === 'meta' ? $this->meta->confirm($plan) : $this->google->confirm($plan);

            return array_merge($result, [
                'ok' => (bool) ($outcome['ok'] ?? false),
                'message' => (string) ($outcome['message'] ?? (($outcome['ok'] ?? false) ? 'Bağlandı.' : 'Bağlanamadı.')),
            ]);
        } catch (Throwable $exception) {
            return array_merge($result, ['message' => $this->reason($exception, 'Hesap bağlanamadı')]);
        }
    }

    /**
     * @param  array<string, mixed>  $service  a BrandSetupProposal::serviceRows() row
     * @return array{key: string, label: string, ok: bool, message: string}
     */
    private function service(array $service, Brand $brand, User $actor, int &$keywordCount): array
    {
        $result = ['key' => 'service:'.$service['name'], 'label' => $service['name'], 'ok' => false, 'message' => ''];
        try {
            if ($service['status'] === 'proposed' && $service['is_new'] && $service['sector_code'] !== null) {
                // New catalog entry under the suggested sector (existing names are found, never duplicated).
                $this->catalog->resolveOrCreate($service['name'], $service['sector_code'], actor: $actor);
            }
            $offering = $this->offerings->resolveOrCreate($brand, $service['name'], actor: $actor)['offering'];
            foreach ($service['aliases'] as $alias) {
                try {
                    $this->offerings->addAlias($offering, $alias, null, $actor);
                } catch (Throwable) {
                    // alias collisions are not fatal
                }
            }
            if ($service['status'] === 'proposed' && $service['is_core']) {
                $offering->forceFill(['is_priority' => true])->save();
            }
            $offering = $offering->fresh();
            $matchingAdded = 0;
            try {
                $matchingAdded = $this->storeMatchingPhrases($service, $offering);
            } catch (Throwable) {
                // the service is added; phrases can be edited under Kütüphane › Hizmetler
            }
            $keywordCount += $this->storeKeywords($service, $offering, $brand, $actor);

            $message = match (true) {
                $service['status'] === 'already' => 'Hizmet markada vardı.',
                $service['is_new'] => 'Katalogda yeni hizmet açıldı ve markaya eklendi.',
                default => 'Katalogdaki hizmet markaya eklendi.',
            };
            if ($matchingAdded > 0) {
                $message .= sprintf(' %d eşleştirme ifadesi eklendi.', $matchingAdded);
            }

            return array_merge($result, ['ok' => true, 'message' => $message]);
        } catch (Throwable $exception) {
            return array_merge($result, ['message' => $this->reason($exception, 'Hizmet eklenemedi')]);
        }
    }

    /** Append the approved matching expressions to the catalog service; operator-entered ones stay. */
    private function storeMatchingPhrases(array $service, $offering): int
    {
        $catalogItem = $offering?->service_catalog_item_id !== null ? ServiceCatalogItem::query()->find($offering->service_catalog_item_id) : null;
        if ($catalogItem === null) {
            return 0;
        }

        return count(app(ServiceKeywordService::class)->append($catalogItem, $service['matching_phrases'] !== [] ? $service['matching_phrases'] : [$service['name']]));
    }

    /**
     * Location-free Search Console queries become the service's keywords in the shared query library
     * (sector → service → query), so brands elsewhere reuse them; locations come from each brand's
     * service areas at render time.
     */
    private function storeKeywords(array $service, $offering, Brand $brand, User $actor): int
    {
        $catalogItem = $offering?->service_catalog_item_id !== null ? ServiceCatalogItem::query()->find($offering->service_catalog_item_id) : null;
        $sector = $catalogItem?->sector ?? $service['sector_code'] ?? $brand->sectorCodes()[0] ?? null;
        if ($catalogItem === null || ! is_string($sector)) {
            return 0;
        }
        // v2: keywords are no longer written to a query library here; Faz 3 rebuilds queries from collected sources.
        $stored = 0;

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $proposed
     * @return array{key: string, label: string, ok: bool, message: string}
     */
    private function businessContext(Brand $brand, array $proposed, User $actor): array
    {
        $context = BrandIntelligenceContext::query()->firstOrNew(['brand_id' => $brand->id]);
        $filled = [];
        foreach (self::CONTEXT_TEXT_LIMITS as $field => $limit) {
            $value = is_scalar($proposed[$field] ?? null) ? $this->clean((string) $proposed[$field], $limit) : '';
            if (trim((string) $context->{$field}) === '' && $value !== '') {
                $context->{$field} = $value;
                $filled[] = $field;
            }
        }
        foreach (['target_audiences', 'differentiators'] as $field) {
            $values = [];
            foreach (is_array($proposed[$field] ?? null) ? $proposed[$field] : [] as $value) {
                $value = is_array($value) ? ($value['name'] ?? $value['label'] ?? null) : $value;
                $value = is_scalar($value) ? $this->clean((string) $value, 160) : '';
                if ($value !== '' && ! in_array($value, $values, true)) {
                    $values[] = $value;
                }
            }
            $values = array_slice($values, 0, 10);
            if (! $this->hasListValue($context->{$field}) && $values !== []) {
                // Audiences use the operator form's shape ({name, note}); differentiators are plain strings.
                $context->{$field} = $field === 'target_audiences' ? array_map(fn (string $v): array => ['name' => $v, 'note' => null], $values) : $values;
                $filled[] = $field;
            }
        }
        if ($filled === []) {
            return ['key' => 'context', 'label' => 'İş bağlamı', 'ok' => true, 'message' => 'İş bağlamı zaten doluydu; değiştirilmedi.'];
        }
        $context->updated_by = $actor->id;
        if (! $context->exists) {
            $context->source = BrandIntelligenceContext::SOURCE_PUBLIC_DISCOVERY;
            BrandIntelligenceContext::withLegacyIdentityProjection(function () use ($context): void {
                $context->business_goals = [];
                $context->conversion_goals = [];
                $context->priority_offerings = [];
                $context->save();
            });
        } else {
            $context->save();
        }

        return ['key' => 'context', 'label' => 'İş bağlamı', 'ok' => true, 'message' => sprintf('İş bağlamının %d alanı siteden dolduruldu (yazdığınız alanlara dokunulmadı).', count($filled))];
    }

    private function hasListValue(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $row) {
            $text = is_array($row) ? ($row['name'] ?? $row['label'] ?? null) : $row;
            if (is_scalar($text) && trim((string) $text) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Runs one step; a throw becomes a "yapılamadı" row instead of a server error.
     *
     * @template T
     *
     * @param  list<array{key: string, label: string, ok: bool, message: string}>  $results
     * @param  callable(): T  $step
     * @return T|null
     */
    private function attempt(array &$results, string $key, string $label, string $fallback, callable $step): mixed
    {
        try {
            return $step();
        } catch (Throwable $exception) {
            $results[] = $this->failed($key, $label, $this->reason($exception, $fallback));

            return null;
        }
    }

    /** Operator-facing Turkish reason; database/programming errors are logged, not shown raw. */
    private function reason(Throwable $exception, string $fallback): string
    {
        if ($exception instanceof ValidationException) {
            $first = collect($exception->errors())->flatten()->first();

            return is_string($first) && $first !== '' ? $first : $fallback.'.';
        }
        report($exception);
        if ($exception instanceof QueryException) {
            return $fallback.': veritabanı kaydı reddetti (ör. çok uzun değer veya aynı kayıt zaten var). Ayrıntı sistem günlüğünde.';
        }

        return $fallback.'. Ayrıntı sistem günlüğünde.';
    }

    /** @return array{key: string, label: string, ok: bool, message: string} */
    private function failed(string $key, string $label, string $message): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => false, 'message' => $message];
    }

    private function clean(string $value, int $max): string
    {
        return trim(mb_substr(trim(mb_scrub(str_replace("\0", '', $value), 'UTF-8')), 0, $max));
    }
}

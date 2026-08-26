@php
    $isTr = app()->getLocale() === 'tr';
    $rawChanges = collect($professional['changes'] ?? []);

    $containsAny = static function (string $haystack, array $needles): bool {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    };

    $changes = $rawChanges->map(function (array $row) use ($containsAny): array {
        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $resourceType = strtolower((string) ($row['change_resource_type'] ?? $row['resource_type'] ?? ''));
        $operation = strtoupper((string) ($row['operation'] ?? 'UNKNOWN'));
        $clientType = strtolower((string) ($row['client_type'] ?? ''));
        $email = strtolower((string) ($row['user_email'] ?? ''));
        $changedFields = data_get($metadata, 'changed_fields', data_get($metadata, 'changedFields', []));
        if (is_string($changedFields)) {
            $changedFields = [$changedFields];
        }
        if (! is_array($changedFields)) {
            $changedFields = [];
        }
        $fieldText = strtolower(implode(' ', array_map(static fn ($value): string => is_scalar($value) ? (string) $value : '', $changedFields)));
        $signal = trim($resourceType.' '.$fieldText.' '.$operation);

        $category = match (true) {
            $containsAny($signal, ['conversion', 'goal']) => 'measurement',
            $containsAny($signal, ['bidding', 'target_cpa', 'target_roas', 'maximize_conversion', 'maximize_conversion_value']) => 'bidding',
            $containsAny($signal, ['budget']) => 'budget',
            $containsAny($signal, ['campaign']) => 'campaign',
            $containsAny($signal, ['ad_group', 'adgroup']) => 'ad_group',
            $containsAny($signal, ['keyword', 'criterion', 'negative']) => 'keyword',
            $containsAny($signal, ['asset', 'ad_group_ad', ' ad ']) => 'creative',
            $containsAny($signal, ['location', 'geo', 'schedule', 'audience']) => 'targeting',
            default => 'other',
        };

        $risk = match (true) {
            $category === 'measurement' => 'critical',
            $containsAny($signal, ['bidding_strategy', 'target_cpa', 'target_roas']) => 'critical',
            $operation === 'REMOVE' && $containsAny($signal, ['campaign', 'conversion']) => 'critical',
            $category === 'budget' => 'high',
            $category === 'bidding' => 'high',
            $category === 'targeting' => 'high',
            $containsAny($signal, ['status', 'enabled', 'paused', 'removed']) && $category === 'campaign' => 'high',
            in_array($category, ['ad_group', 'keyword', 'creative'], true) => 'medium',
            default => 'low',
        };

        $actorSignal = trim($clientType.' '.$email);
        $actor = match (true) {
            $containsAny($actorSignal, ['google_ads_api', 'api_client', 'api']) => 'api',
            $email !== '' => 'human',
            $containsAny($actorSignal, ['automated', 'google', 'system', 'rule', 'script']) => 'google',
            default => 'unknown',
        };

        return [
            ...$row,
            'metadata' => $metadata,
            'changed_fields' => $changedFields,
            'category_key' => $category,
            'risk_key' => $risk,
            'actor_key' => $actor,
        ];
    });

    $riskWeight = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
    $attention = $changes
        ->filter(fn (array $row): bool => in_array($row['risk_key'], ['critical', 'high'], true))
        ->sortByDesc(fn (array $row): int => $riskWeight[$row['risk_key']] ?? 0)
        ->take(6)
        ->values();

    $actorCounts = [
        'human' => $changes->where('actor_key', 'human')->count(),
        'google' => $changes->where('actor_key', 'google')->count(),
        'api' => $changes->where('actor_key', 'api')->count(),
        'unknown' => $changes->where('actor_key', 'unknown')->count(),
    ];

    $riskLabel = fn (string $risk): string => match ($risk) {
        'critical' => $isTr ? 'Kritik' : 'Critical',
        'high' => $isTr ? 'Yüksek' : 'High',
        'medium' => $isTr ? 'Orta' : 'Medium',
        default => $isTr ? 'Düşük' : 'Low',
    };
    $riskClasses = fn (string $risk): string => match ($risk) {
        'critical' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
        'high' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
        'medium' => 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20',
        default => 'bg-gray-100 text-gray-600 ring-gray-200 dark:bg-white/5 dark:text-gray-300 dark:ring-gray-700',
    };
    $actorLabel = fn (string $actor): string => match ($actor) {
        'human' => $isTr ? 'Kullanıcı' : 'Human',
        'google' => $isTr ? 'Google / otomasyon' : 'Google / automation',
        'api' => 'API',
        default => $isTr ? 'Bilinmiyor' : 'Unknown',
    };
    $categoryLabel = fn (string $category): string => match ($category) {
        'measurement' => $isTr ? 'Dönüşüm & ölçüm' : 'Conversion & measurement',
        'bidding' => $isTr ? 'Teklif stratejisi' : 'Bidding',
        'budget' => $isTr ? 'Bütçe' : 'Budget',
        'campaign' => $isTr ? 'Kampanya' : 'Campaign',
        'ad_group' => $isTr ? 'Reklam grubu' : 'Ad group',
        'keyword' => $isTr ? 'Anahtar kelime / kriter' : 'Keyword / criterion',
        'creative' => $isTr ? 'Reklam / varlık' : 'Ad / asset',
        'targeting' => $isTr ? 'Hedefleme' : 'Targeting',
        default => $isTr ? 'Diğer' : 'Other',
    };
    $operationLabel = fn (?string $operation): string => match (strtoupper((string) $operation)) {
        'CREATE' => $isTr ? 'Oluşturuldu' : 'Created',
        'UPDATE' => $isTr ? 'Güncellendi' : 'Updated',
        'REMOVE' => $isTr ? 'Kaldırıldı' : 'Removed',
        default => $operation ?: '—',
    };
@endphp

<div class="space-y-5">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-violet-600 dark:text-violet-300">{{ $isTr ? 'DEĞİŞİKLİK ZEKÂSI' : 'CHANGE INTELLIGENCE' }}</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Hesapta ne değişti ve ne kadar dikkat gerektiriyor?' : 'What changed in the account, and what deserves attention?' }}</h2>
            <p class="mt-1 max-w-4xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Google Ads Change Event kayıtlarını yalnız listelemek yerine aktör, konu ve operasyonel risk açısından sınıflandırır. MOXDOP performans etkisini kanıt olmadan değişikliğe bağlamaz.' : 'Google Ads Change Events are classified by actor, topic and operational risk instead of being shown as a raw audit log. MOXDOP does not attribute performance impact to a change without evidence.' }}</p>
        </div>
        <span class="inline-flex w-fit items-center rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ $isTr ? 'Salt okunur sağlayıcı denetimi' : 'Read-only provider audit' }}</span>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $isTr ? 'Toplam değişiklik' : 'Total changes' }}</p><p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($changes->count(), 0, ',', '.') }}</p></div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $isTr ? 'Kritik / yüksek' : 'Critical / high' }}</p><p class="mt-2 text-2xl font-bold {{ $attention->count() ? 'text-amber-600 dark:text-amber-300' : 'text-gray-900 dark:text-white' }}">{{ $changes->whereIn('risk_key', ['critical','high'])->count() }}</p></div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $isTr ? 'Kullanıcı' : 'Human' }}</p><p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ $actorCounts['human'] }}</p></div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">Google / Automation</p><p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ $actorCounts['google'] }}</p></div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">API</p><p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ $actorCounts['api'] }}</p></div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $isTr ? 'Bilinmeyen aktör' : 'Unknown actor' }}</p><p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ $actorCounts['unknown'] }}</p></div>
    </div>

    @if ($attention->isNotEmpty())
        <section class="overflow-hidden rounded-2xl border border-amber-200 bg-amber-50/60 dark:border-amber-500/20 dark:bg-amber-500/5">
            <div class="border-b border-amber-200 px-4 py-3 dark:border-amber-500/20">
                <div class="flex items-center justify-between gap-3"><div><h3 class="font-semibold text-amber-950 dark:text-amber-100">{{ $isTr ? 'Önce incelenecek değişiklikler' : 'Changes to review first' }}</h3><p class="mt-1 text-xs text-amber-800/80 dark:text-amber-200/70">{{ $isTr ? 'Ölçüm, teklif, bütçe, hedefleme veya kampanya durumunu etkileyebilecek olaylar.' : 'Events that may affect measurement, bidding, budget, targeting or campaign state.' }}</p></div><span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-800 dark:bg-amber-500/15 dark:text-amber-200">{{ $attention->count() }}</span></div>
            </div>
            <div class="divide-y divide-amber-100 dark:divide-amber-500/10">
                @foreach ($attention as $row)
                    <div class="grid gap-3 px-4 py-3 md:grid-cols-[110px_1fr_auto] md:items-center">
                        <div><span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold uppercase ring-1 ring-inset {{ $riskClasses($row['risk_key']) }}">{{ $riskLabel($row['risk_key']) }}</span></div>
                        <div class="min-w-0"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $categoryLabel($row['category_key']) }} · {{ $operationLabel($row['operation'] ?? null) }}</p><p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">{{ $row['change_resource_name'] ?? $row['change_resource_type'] ?? '—' }}</p></div>
                        <div class="text-xs text-gray-500 md:text-right"><p>{{ $actorLabel($row['actor_key']) }}</p><p class="mt-1">{{ $row['changed_at'] ?? '—' }}</p></div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <div class="rounded-xl bg-blue-50 px-4 py-3 text-sm text-blue-800 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-200 dark:ring-blue-500/20">
        <strong>{{ $isTr ? 'Karar sınırı:' : 'Decision boundary:' }}</strong>
        {{ $isTr ? 'Risk seviyesi değişiklik türüne göre operasyonel önceliktir; performansın gerçekten bu değişiklik yüzünden arttığı veya düştüğü anlamına gelmez. Before/after etki analizi ancak aynı kaynak için kanonik performans kanıtı kurulabildiğinde gösterilmelidir.' : 'Risk is an operational priority based on the change type; it does not mean the change caused performance to improve or decline. Before/after impact should only be shown when canonical performance evidence can be tied to the same resource.' }}
    </div>

    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Değişiklik zaman çizelgesi' : 'Change timeline' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Sağlayıcıdan toplanmış gerçek Change Event kayıtları; en yeni olay önce.' : 'Real provider Change Event records, newest first.' }}</p></div>
        <div class="overflow-x-auto">
            <table class="min-w-[1150px] w-full text-sm">
                <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]"><tr>
                    <th class="px-4 py-2.5 text-left">{{ $isTr ? 'Risk' : 'Risk' }}</th>
                    <th class="px-3 py-2.5 text-left">{{ $isTr ? 'Tarih' : 'Date' }}</th>
                    <th class="px-3 py-2.5 text-left">{{ $isTr ? 'Aktör' : 'Actor' }}</th>
                    <th class="px-3 py-2.5 text-left">{{ $isTr ? 'Kategori' : 'Category' }}</th>
                    <th class="px-3 py-2.5 text-left">{{ $isTr ? 'Kaynak' : 'Resource' }}</th>
                    <th class="px-3 py-2.5 text-left">{{ $isTr ? 'İşlem' : 'Operation' }}</th>
                    <th class="px-4 py-2.5 text-left">{{ $isTr ? 'Değişen alanlar / detay' : 'Changed fields / detail' }}</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($changes as $row)
                        @php
                            $m = $row['metadata'] ?? [];
                            $detail = collect($m)
                                ->except(['provider','api_version','collector_layer','provider_fact','derived_rates_stored','changed_fields','changedFields'])
                                ->map(fn ($v, $k) => $k.': '.(is_scalar($v) ? $v : json_encode($v)))
                                ->take(4)
                                ->implode(' · ');
                            $fields = collect($row['changed_fields'] ?? [])->filter(fn ($v) => is_scalar($v))->map(fn ($v) => (string) $v)->take(5)->implode(', ');
                        @endphp
                        <tr class="align-top">
                            <td class="px-4 py-3"><span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold uppercase ring-1 ring-inset {{ $riskClasses($row['risk_key']) }}">{{ $riskLabel($row['risk_key']) }}</span></td>
                            <td class="whitespace-nowrap px-3 py-3 text-xs text-gray-500">{{ $row['changed_at'] ?? '—' }}</td>
                            <td class="px-3 py-3"><p class="text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $actorLabel($row['actor_key']) }}</p><p class="mt-1 max-w-[220px] truncate text-[11px] text-gray-400">{{ $row['user_email'] ?? $row['client_type'] ?? '—' }}</p></td>
                            <td class="px-3 py-3 text-xs font-medium text-gray-700 dark:text-gray-300">{{ $categoryLabel($row['category_key']) }}</td>
                            <td class="px-3 py-3"><p class="max-w-[260px] truncate text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $row['change_resource_name'] ?? '—' }}</p><p class="mt-1 text-[10px] text-gray-400">{{ $row['change_resource_type'] ?? $row['resource_type'] ?? '—' }}</p></td>
                            <td class="px-3 py-3 text-xs text-gray-600 dark:text-gray-300">{{ $operationLabel($row['operation'] ?? null) }}</td>
                            <td class="max-w-xl px-4 py-3 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $fields !== '' ? $fields : ($detail !== '' ? $detail : '—') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-12 text-center text-sm text-gray-400">{{ $isTr ? 'Toplanmış Change Event kaydı yok. Veri yokken MOXDOP değişiklik veya etki uydurmaz.' : 'No collected Change Event records. MOXDOP does not invent changes or impact when provider evidence is absent.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

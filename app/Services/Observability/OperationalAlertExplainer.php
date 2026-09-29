<?php

namespace App\Services\Observability;

use App\Models\CoreIntegration;
use App\Models\Observability\OperationalAlert;
use App\Support\Operator\CollectionErrorExplainer;
use App\Support\Operator\DatasetLabels;
use App\Support\Operator\OperatorMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Reads a system alert (OperationalAlert) as an OperatorMessage: which brand / asset / account and which data
 * (Ne oldu), the impact (Neden önemli), the concrete fix (Ne yapmalısın) and the exact place to fix it (Nereden) —
 * asset data sources page, reconnect screen or "Şimdi güncelle" — instead of the generic Sistem sağlığı page.
 *
 * Built at read time from the alert's rule key and `observed`, so rows written by older code (English titles,
 * "3 failed CollectionRun(s) in the last 3600s") read the same way as new ones.
 */
final class OperationalAlertExplainer
{
    /** @var array<string, OperatorMessage> readers of the same alert in one request share the result */
    private array $memo = [];

    public function __construct(private readonly AlertSubjects $subjects) {}

    public function explain(OperationalAlert $alert): OperatorMessage
    {
        $key = $alert->id.'|'.$alert->updated_at?->getTimestamp();
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $observed = is_array($alert->observed) ? $alert->observed : [];
        try {
            $parts = match (true) {
                $alert->rule_key === 'dataset_stale' => $this->datasetStale($observed),
                $alert->rule_key === 'collection_repeated_failure' => $this->collectionFailures($observed),
                $alert->rule_key === 'collection_stuck' => $this->collectionStuck($observed),
                str_starts_with((string) $alert->rule_key, 'resource-automation.') => $this->automation($alert, $observed),
                $alert->rule_key === 'credential_reconnect_required' => $this->reconnect($observed),
                $alert->rule_key === 'credential_expiring' => $this->expiring($observed),
                $alert->rule_key === 'provider_rate_limited' => $this->rateLimited($observed),
                $alert->rule_key === 'provider_error_rate' => $this->errorRate($observed),
                $alert->rule_key === 'queue_interactive_backlog', $alert->rule_key === QueueWaitMonitor::RULE_KEY => $this->queue($alert, $observed),
                $alert->rule_key === 'worker_heartbeat_missing' => $this->workers(),
                default => null,
            };
        } catch (Throwable $error) {
            report($error);
            $parts = null;
        }
        $parts ??= $this->generic($alert);

        $occurrences = max(1, (int) ($alert->occurrence_count ?? 1));

        return $this->memo[$key] = new OperatorMessage(
            title: $parts['title'],
            what: $parts['what'],
            why: $parts['why'],
            action: $parts['action'],
            linkUrl: $parts['link_url'] ?? null,
            linkLabel: $parts['link_label'] ?? null,
            button: $parts['button'] ?? null,
            occurrences: $occurrences,
            firstSeen: $occurrences > 1 ? ($alert->first_opened_at ?? $alert->first_observed_at) : null,
            assetId: $parts['asset_id'] ?? null,
            brandId: $parts['brand_id'] ?? null,
            topic: 'system:'.self::topicRule((string) $alert->rule_key),
        );
    }

    /** Komuta merkezi topic rule of an alert rule ("resource-automation.collection" → "resource-automation"). */
    public static function topicRule(string $ruleKey): string
    {
        return match (true) {
            str_starts_with($ruleKey, 'resource-automation.') => $ruleKey,
            str_starts_with($ruleKey, 'credential_') => 'credential',
            str_starts_with($ruleKey, 'provider_') => 'provider',
            str_starts_with($ruleKey, 'queue_'), $ruleKey === 'worker_heartbeat_missing' => 'queue',
            default => $ruleKey,
        };
    }

    /** @return array<string, mixed> */
    private function datasetStale(array $observed): array
    {
        $affected = $this->affected($observed);
        $total = (int) ($observed['affected_total'] ?? count($affected));
        if ($affected === []) {
            $count = (int) ($observed['stale_or_blocked_count'] ?? 0);

            return [
                'title' => 'Bazı hesapların verisi güncel değil',
                'what' => ($count > 0 ? $count.' hesap / veri kaynağında' : 'Bazı hesaplarda').' veriler zamanında yenilenmedi ya da çekilemiyor. Etkilenen hesaplar bir sonraki kontrolde (birkaç dakika içinde) burada adıyla listelenir.',
                'why' => 'Raporlar, uyarılar ve öneriler bu hesaplarda eski veriye dayanıyor.',
                'action' => 'Keşfedilen varlıklarda sorunlu kaynakları açın; bağlantı sorunu olanı yeniden bağlayın, diğerlerinde "Verileri yenile" ile çekimi başlatın.',
                'link_url' => $this->route('operator.integrations.discovered'),
                'link_label' => 'Keşfedilen varlıklarda aç',
            ];
        }

        $fix = $this->primaryFix($affected);
        $blocked = collect($affected)->filter(fn (array $a): bool => array_intersect($a['states'] ?? [], ['ACTION_REQUIRED', 'INTEGRITY_BLOCKED']) !== [])->count();
        $what = sprintf('%d hesapta veri %s: %s.', $total, $blocked > 0 ? 'güncellenemiyor' : 'zamanında yenilenmedi', $this->list($affected, $total));
        if ($fix !== null) {
            $what .= ' Son hata: '.$fix['problem'].'.';
        }

        return [
            'title' => $total === 1 ? $this->label($affected[0]).' verisi güncel değil' : $total.' hesabın verisi güncel değil',
            'what' => $what,
            'why' => 'Bu hesaplardaki raporlar, uyarılar ve öneriler eski veriye dayanıyor; müşteriye yanlış rakam gidebilir.',
            'action' => $fix['fix'] ?? 'Çekim kendiliğinden yeniden denenir. Hemen güncel veri gerekiyorsa "Şimdi güncelle" ile başlatın.',
        ] + $this->placeFor($affected, $fix);
    }

    /** @return array<string, mixed> */
    private function collectionFailures(array $observed): array
    {
        $affected = $this->affected($observed);
        $count = (int) ($observed['failure_count'] ?? count($affected));
        $minutes = max(1, intdiv((int) ($observed['window_seconds'] ?? 3600), 60));
        $period = $minutes % 60 === 0 ? 'Son '.intdiv($minutes, 60).' saatte' : 'Son '.$minutes.' dakikada';
        if ($affected === []) {
            return [
                'title' => 'Veri çekimleri üst üste başarısız oluyor',
                'what' => sprintf('%s %d veri çekimi başarısız oldu. Hangi hesaplar olduğu bir sonraki kontrolde (birkaç dakika içinde) burada listelenir.', $period, $count),
                'why' => 'Başarısız çekimlerin hesaplarında raporlar ve öneriler eski veriyle kalır.',
                'action' => 'Arka plan işleri sayfasında başarısız çekimlerin hata nedenini okuyun; bağlantı sorunuysa yeniden bağlayın.',
                'link_url' => $this->route('operator.settings.background-operations'),
                'link_label' => 'Arka plan işlerini aç',
            ];
        }
        $fix = $this->primaryFix($affected);

        return [
            'title' => count($affected) === 1 ? $this->label($affected[0]).' verisi çekilemiyor' : 'Veri çekimleri üst üste başarısız oluyor',
            'what' => sprintf('%s %d veri çekimi başarısız oldu: %s.', $period, $count, $this->list($affected, (int) ($observed['affected_total'] ?? count($affected))))
                .($fix !== null ? ' Neden: '.$fix['problem'].'.' : ''),
            'why' => 'Bu hesaplarda yeni veri gelmiyor; raporlar ve öneriler eski veriyle kalır.',
            'action' => $fix['fix'] ?? '"Şimdi güncelle" ile tekrar deneyin; yine başarısız olursa yazılım ekibine haber verin.',
        ] + $this->placeFor($affected, $fix);
    }

    /** @return array<string, mixed> */
    private function collectionStuck(array $observed): array
    {
        $affected = $this->affected($observed);
        $count = (int) ($observed['candidate_count'] ?? count($affected));

        return [
            'title' => 'Takılı kalan veri çekimi var',
            'what' => $affected !== []
                ? sprintf('%d veri çekimi uzun süredir ilerlemiyor: %s.', $count, $this->list($affected, (int) ($observed['affected_total'] ?? count($affected))))
                : sprintf('%d veri çekimi uzun süredir ilerlemiyor.', $count),
            'why' => 'Takılı çekim bitmeden aynı hesabın yeni verisi gelmez.',
            'action' => 'Sistem takılı çekimleri kendiliğinden kapatıp yeniden dener. Bir saatten uzun sürerse işçilerin çalıştığını Sistem sağlığı › İşçiler bölümünden kontrol edin.',
        ] + ($affected !== [] ? $this->placeFor($affected, null) : [
            'link_url' => $this->route('operator.settings.background-operations'),
            'link_label' => 'Arka plan işlerini aç',
        ]);
    }

    /** "Hesap güncellemesi durdu" — one account's automatic collection (or query import) stopped. */
    private function automation(OperationalAlert $alert, array $observed): array
    {
        $phase = (string) ($observed['phase'] ?? str_replace('resource-automation.', '', (string) $alert->rule_key));
        $reason = (string) ($observed['reason'] ?? 'collection_failed');
        $affected = $this->affected($observed);
        if ($affected === [] && ctype_digit((string) $alert->scope_key)) {
            $affected = $this->subjects->describe([['resource_id' => (int) $alert->scope_key]]);
        }
        $subject = $affected[0] ?? null;
        $source = DatasetLabels::source($subject['source'] ?? null);
        $account = trim((string) ($subject['account'] ?? $subject['asset'] ?? ''));
        if ($account === '' && str_contains((string) $alert->title, ' · ')) {
            $account = trim(explode(' · ', (string) $alert->title, 2)[1]);
        }
        $where = (trim((string) ($subject['brand'] ?? '')) !== '' ? $subject['brand'].' · ' : '').$source.' hesabı'.($account !== '' ? ' "'.$account.'"' : '');
        $automationId = isset($observed['automation_id']) ? (int) $observed['automation_id'] : ($subject['automation_id'] ?? null);

        if ($phase === 'queries') {
            [$problem, $fix] = match ($reason) {
                'mapping_invalid' => ['seçilen sektör veya hizmetler artık geçerli değil', 'Hesabın sorgu eşleştirmesini (sektör / hizmetler) Sorgu kütüphanesinde düzenleyin; kaydedince aktarım kendiliğinden sürer.'],
                'invalid_rows' => ['bazı sorgu satırları kaydedilemedi', 'Sorgu kütüphanesinde aktarım geçmişinden geçersiz satırları inceleyin; geri kalan sorgular aktarıldı.'],
                default => ['aktarım yarıda kesildi', 'Sorgu kütüphanesinde hesabın satırında "Aktarımı sürdür" ile kaldığı yerden devam ettirin; kaydedilen satırlar korunuyor.'],
            };

            return [
                'title' => 'Sorgu aktarımı durdu · '.($account !== '' ? $account : $source),
                'what' => $where.' için arama sorgularının kütüphaneye otomatik aktarımı durdu: '.$problem.'.',
                'why' => 'Yeni arama sorguları hizmetlere atanmıyor; SEO ve talep önerileri eksik kalır.',
                'action' => $fix,
                'link_url' => $this->route('operator.library.queries'),
                'link_label' => 'Sorgularda aç',
                'asset_id' => $subject['asset_id'] ?? null,
                'brand_id' => $subject['brand_id'] ?? null,
            ];
        }

        // A plain "collection_failed" stop: the account's last dataset error says why.
        $category = $reason === 'collection_failed' ? ($subject['error_category'] ?? null) : $reason;
        $explained = CollectionErrorExplainer::explain($category ?? 'collection_failed', $subject['provider'] ?? null, $subject['account_email'] ?? null);
        $failures = $reason === 'collection_failed' ? 'Otomatik güncelleme üst üste 3 kez başarısız olduğu için durduruldu' : 'Otomatik güncelleme durduruldu';
        $place = $this->placeFor($subject !== null ? [$subject] : [], $explained);
        if ($automationId !== null && in_array($explained['kind'], ['retry', 'grant_access', 'wait', 'developer'], true)) {
            $place['button'] = ['label' => 'Şimdi güncelle', 'run_now' => $automationId];
        }

        return [
            'title' => 'Hesap güncellemesi durdu · '.($account !== '' ? $account : $source),
            'what' => $where.': '.$failures.'. Neden: '.$explained['problem'].'.',
            'why' => 'Bu hesabın verisi yenilenmiyor; raporlar, uyarılar ve öneriler eski veriye dayanıyor. Sistem kendiliğinden yeniden denemez.',
            'action' => $explained['fix'],
        ] + $place;
    }

    /** @return array<string, mixed> */
    private function reconnect(array $observed): array
    {
        $integration = $this->integration($observed);
        $provider = (string) ($observed['provider'] ?? $integration?->provider ?? 'google');
        $company = CollectionErrorExplainer::company($provider);
        $name = $this->integrationName($integration, $company);

        return [
            'title' => $company.' bağlantısı yenilenmeli · '.$name,
            'what' => $name.' bağlantısının izni sona erdi veya geri alındı; bu bağlantıdaki hiçbir hesabın verisi çekilemiyor.',
            'why' => 'Bağlantı yenilenene kadar bu bağlantıya bağlı bütün markaların raporları ve önerileri eski veride kalır.',
            'action' => '"Yeniden bağlan" ile '.$company.' izin ekranını açıp '.($this->email($integration) ?? 'bağlantıyı yapan kullanıcı').' ile onaylayın. Durmuş çekimler kendiliğinden devam eder.',
        ] + $this->reconnectPlace($integration, $provider);
    }

    /** @return array<string, mixed> */
    private function expiring(array $observed): array
    {
        $integration = $this->integration($observed);
        $provider = (string) ($observed['provider'] ?? $integration?->provider ?? 'google');
        $company = CollectionErrorExplainer::company($provider);
        $name = $this->integrationName($integration, $company);
        $expires = null;
        try {
            $expires = isset($observed['expires_at']) ? CarbonImmutable::parse((string) $observed['expires_at']) : null;
        } catch (Throwable) {
        }
        $days = $expires !== null ? max(0, (int) ceil(now()->diffInDays($expires, false))) : null;

        return [
            'title' => $company.' bağlantısının izni bitiyor · '.$name,
            'what' => $name.' bağlantısının izni '.($expires !== null ? OperatorMessage::shortDate($expires).' tarihinde ('.$days.' gün sonra)' : 'birkaç gün içinde').' bitiyor.',
            'why' => 'İzin biterse bu bağlantıdaki bütün hesapların veri çekimi durur.',
            'action' => 'Bitmeden "Yeniden bağlan" ile '.$company.' izin ekranını açıp aynı kullanıcıyla onaylayın; izin süresi yenilenir.',
        ] + $this->reconnectPlace($integration, $provider);
    }

    /** @return array<string, mixed> */
    private function rateLimited(array $observed): array
    {
        $company = CollectionErrorExplainer::company((string) ($observed['provider'] ?? ''));

        return [
            'title' => $company.' istek sınırına takılıyor',
            'what' => sprintf('Son %s içinde %s\'a yapılan %d isteğin %d tanesi "çok fazla istek" nedeniyle geri çevrildi.', $this->window($observed), $company, (int) ($observed['attempts'] ?? $observed['denominator_attempts'] ?? 0), (int) ($observed['rate_limits'] ?? 0)),
            'why' => 'Veri çekimleri yavaşlıyor; bazı hesapların verisi gecikmeli gelir.',
            'action' => 'Bir şey yapmanıza gerek yok: sistem yavaşlayıp kendiliğinden yeniden dener. Günlük kota dolduysa çekim yarın devam eder.',
            'link_url' => $this->route('operator.settings.system-health').'#canli-dogrulama',
            'link_label' => 'Canlı doğrulamayı aç',
        ];
    }

    /** @return array<string, mixed> */
    private function errorRate(array $observed): array
    {
        $company = CollectionErrorExplainer::company((string) ($observed['provider'] ?? ''));
        $parts = array_filter([
            (int) ($observed['auth_errors'] ?? 0) > 0 ? (int) $observed['auth_errors'].' yetki hatası' : null,
            (int) ($observed['server_errors'] ?? 0) > 0 ? (int) $observed['server_errors'].' sunucu hatası' : null,
            (int) ($observed['timeouts'] ?? 0) > 0 ? (int) $observed['timeouts'].' zaman aşımı' : null,
        ]);
        $auth = (int) ($observed['auth_errors'] ?? 0) > 0;

        return [
            'title' => $company.' isteklerinin çoğu hata veriyor',
            'what' => sprintf('Son %s içinde %s\'a yapılan %d isteğin %d tanesi hata döndü%s.', $this->window($observed), $company, (int) ($observed['denominator_attempts'] ?? $observed['attempts'] ?? 0), (int) ($observed['numerator_errors'] ?? 0), $parts !== [] ? ' ('.implode(', ', $parts).')' : ''),
            'why' => 'Hata veren isteklerin hesaplarında veri eksik kalır.',
            'action' => $auth
                ? 'Yetki hataları var: Entegrasyonlar\'da hangi '.$company.' bağlantısının "yeniden bağlan" istediğine bakın ve yenileyin.'
                : 'Genellikle '.$company.' tarafındaki geçici bir kesintidir ve kendiliğinden düzelir. Bir günden uzun sürerse yazılım ekibine haber verin.',
            'link_url' => $auth ? $this->route('operator.integrations') : $this->route('operator.settings.system-health').'#canli-dogrulama',
            'link_label' => $auth ? 'Entegrasyonları aç' : 'Canlı doğrulamayı aç',
        ];
    }

    /** @return array<string, mixed> */
    private function queue(OperationalAlert $alert, array $observed): array
    {
        $wait = (int) ($observed['wait_seconds'] ?? $observed['oldest_queued_job_age_seconds'] ?? 0);

        return [
            'title' => 'Arka plan işleri gecikiyor'.(($observed['queue'] ?? null) !== null ? ' · '.$observed['queue'] : ''),
            'what' => ($observed['queue'] ?? null) !== null
                ? sprintf('"%s" kuyruğundaki işlerin başlaması ~%d dakika sürüyor (olağan: %d dakika).', $observed['queue'], (int) ceil($wait / 60), (int) ceil(((int) ($observed['threshold_seconds'] ?? 0)) / 60))
                : sprintf('%d iş sırada bekliyor; en eskisi %d dakikadır başlamadı.', (int) ($observed['pending_jobs'] ?? 0), (int) ceil($wait / 60)),
            'why' => 'Veri çekimleri, raporlar ve "Şimdi güncelle" gibi istekler geç tamamlanır.',
            'action' => 'Genellikle yoğunluk geçince kendiliğinden düzelir. Bir saatten uzun sürerse sunucu yöneticisine işçi sayısını artırmasını söyleyin.',
            'link_url' => $this->route('operator.settings.background-operations'),
            'link_label' => 'Arka plan işlerini aç',
        ];
    }

    /** @return array<string, mixed> */
    private function workers(): array
    {
        return [
            'title' => 'Arka plan işçileri çalışmıyor',
            'what' => 'Veri çekimlerini ve raporları çalıştıran arka plan işçilerinden sinyal gelmiyor.',
            'why' => 'İşçiler durduysa hiçbir hesabın verisi yenilenmez; "Şimdi güncelle" ve raporlar bekler.',
            'action' => 'Sunucu yöneticisine hemen haber verin: Horizon / queue işçilerinin yeniden başlatılması gerekir.',
            'link_url' => $this->route('operator.settings.system-health'),
            'link_label' => 'Sistem sağlığında işçileri aç',
        ];
    }

    /** @return array<string, mixed> */
    private function generic(OperationalAlert $alert): array
    {
        $summary = trim((string) $alert->summary);

        return [
            'title' => (string) $alert->title,
            'what' => $summary !== '' ? $summary : (string) $alert->title,
            'why' => 'MoxDOP\'un kendi çalışmasında bir sorun var; çözülene kadar bazı veriler veya işlemler gecikebilir.',
            'action' => 'Sistem sağlığı ekranında ayrıntıya bakın; sürerse yazılım ekibine haber verin.',
            'link_url' => $this->route('operator.settings.system-health'),
            'link_label' => 'Sistem sağlığını aç',
        ];
    }

    /**
     * Where to go and which button fits: one asset → its data sources page; several → Portföy sağlığı; an auth error
     * on one connection → the reconnect screen; a retryable error on one account → "Şimdi güncelle".
     *
     * @param  list<array<string, mixed>>  $affected
     * @param  array{problem: string, fix: string, kind: string}|null  $fix
     * @return array<string, mixed>
     */
    private function placeFor(array $affected, ?array $fix): array
    {
        $assets = array_values(array_unique(array_filter(array_column($affected, 'asset_id'))));
        $brands = array_values(array_unique(array_filter(array_column($affected, 'brand_id'))));
        $place = count($assets) === 1
            ? ['link_url' => $this->route('operator.asset.sources', ['assetId' => $assets[0]]), 'link_label' => 'Varlığın veri kaynaklarını aç', 'asset_id' => (int) $assets[0]]
            : ['link_url' => $this->route('operator.integrations.discovered'), 'link_label' => 'Keşfedilen varlıklarda aç'];
        if (count($brands) === 1) {
            $place['brand_id'] = (int) $brands[0];
        }

        $integrations = array_values(array_unique(array_filter(array_column($affected, 'integration_id'))));
        if (($fix['kind'] ?? null) === 'reconnect' && count($integrations) === 1) {
            $place['button'] = ['label' => 'Yeniden bağlan', 'url' => $this->reconnectUrl((int) $integrations[0], (string) ($affected[0]['provider'] ?? 'google'))];
        }
        $automations = array_values(array_unique(array_filter(array_column($affected, 'automation_id'))));
        if (in_array($fix['kind'] ?? 'retry', ['retry', 'grant_access'], true) && count($automations) === 1) {
            $place['button'] = ['label' => 'Şimdi güncelle', 'run_now' => (int) $automations[0]];
        }

        return $place;
    }

    /** @return array<string, mixed> */
    private function reconnectPlace(?CoreIntegration $integration, string $provider): array
    {
        $page = $this->route(CollectionErrorExplainer::providerOf($provider) === 'meta' ? 'operator.integrations.meta' : 'operator.integrations.google');
        if ($integration === null) {
            return ['link_url' => $page, 'link_label' => 'Entegrasyonu aç'];
        }

        return [
            'link_url' => $page,
            'link_label' => 'Entegrasyonu aç',
            'button' => ['label' => 'Yeniden bağlan', 'url' => $this->reconnectUrl((int) $integration->id, $provider)],
        ];
    }

    private function reconnectUrl(int $integrationId, string $provider): ?string
    {
        return $this->route(CollectionErrorExplainer::providerOf($provider) === 'meta' ? 'integrations.meta.authorize' : 'integrations.google.authorize', ['integration' => $integrationId]);
    }

    /**
     * The most common error across the affected accounts, explained.
     *
     * @param  list<array<string, mixed>>  $affected
     * @return array{problem: string, fix: string, kind: string}|null
     */
    private function primaryFix(array $affected): ?array
    {
        $counts = [];
        $first = [];
        foreach ($affected as $entry) {
            $category = $entry['error_category'] ?? null;
            if (in_array('ACTION_REQUIRED', $entry['states'] ?? [], true) && $category === null) {
                $category = 'authentication';
            }
            if ($category === null) {
                continue;
            }
            $normalized = CollectionErrorExplainer::normalize($category);
            $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
            $first[$normalized] ??= $entry;
        }
        if ($counts === []) {
            return null;
        }
        arsort($counts);
        $top = (string) array_key_first($counts);
        $entry = $first[$top];
        $explained = CollectionErrorExplainer::explain($entry['error_category'] ?? 'authentication', $entry['provider'] ?? null, $entry['account_email'] ?? null);
        if (count($counts) > 1) {
            $explained['problem'] .= ' ('.$counts[$top].' hesapta; diğerlerinde farklı nedenler var)';
        } elseif ($counts[$top] < count($affected)) {
            $explained['problem'] .= ' ('.($counts[$top] === 1 ? $this->label($entry) : $counts[$top].' hesapta').')';
            $explained['fix'] .= ' Diğer hesaplarda hata yok; onların çekimi kendiliğinden yeniden denenir.';
        }

        return $explained;
    }

    /**
     * "Atlas Dental · atlasdental.com (Search Console günlük tıklamalar), … +3".
     *
     * @param  list<array<string, mixed>>  $affected
     */
    private function list(array $affected, int $total): string
    {
        $shown = array_slice($affected, 0, 5);
        $names = array_map(function (array $entry): string {
            $datasets = $entry['datasets'] ?? [];

            return $this->label($entry).' ('.($datasets !== [] ? DatasetLabels::datasets($datasets) : DatasetLabels::source($entry['source'] ?? null)).')';
        }, $shown);
        $rest = max(0, $total - count($shown));

        return implode(', ', $names).($rest > 0 ? ' +'.$rest : '');
    }

    /** "Atlas Dental · atlasdental.com" (brand · account, or brand · asset). */
    private function label(array $entry): string
    {
        $name = trim((string) ($entry['account'] ?? '')) ?: trim((string) ($entry['asset'] ?? ''));
        if ($name === '') {
            $name = DatasetLabels::source($entry['source'] ?? null).' hesabı';
        }
        $brand = trim((string) ($entry['brand'] ?? ''));

        return $brand !== '' && $brand !== $name ? $brand.' · '.$name : $name;
    }

    /** @return list<array<string, mixed>> */
    private function affected(array $observed): array
    {
        $affected = $observed['affected'] ?? [];

        return is_array($affected) ? array_values(array_filter($affected, 'is_array')) : [];
    }

    private function integration(array $observed): ?CoreIntegration
    {
        $id = (int) ($observed['integration_id'] ?? 0);

        return $id > 0 ? CoreIntegration::query()->find($id) : null;
    }

    private function integrationName(?CoreIntegration $integration, string $company): string
    {
        $name = trim((string) ($integration?->name ?? ''));
        $email = $this->email($integration);

        return ($name !== '' ? $name : $company.' bağlantısı').($email !== null && ! str_contains($name, $email) ? ' ('.$email.')' : '');
    }

    private function email(?CoreIntegration $integration): ?string
    {
        $email = is_array($integration?->config) ? ($integration->config['account_email'] ?? null) : null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    private function window(array $observed): string
    {
        $minutes = max(1, intdiv((int) ($observed['window_seconds'] ?? 3600), 60));

        return $minutes % 60 === 0 ? intdiv($minutes, 60).' saat' : $minutes.' dakika';
    }

    private function route(string $name, array $params = []): ?string
    {
        return Route::has($name) ? route($name, $params) : null;
    }
}

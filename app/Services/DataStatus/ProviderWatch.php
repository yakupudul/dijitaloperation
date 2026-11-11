<?php

namespace App\Services\DataStatus;

use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Services\Assistant\PushNotifier;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Sağlayıcı nöbeti (Bağlantı Düzeni 4.2 and 4.5). Two things break a connection without anybody touching it: an
 * authorization that runs out and an API version the provider switches off. Both have a date, so both are watched:
 * an authorization is told 7 days before it ends, a version 30 days before. Read only; the daily Bağlantı sağlığı
 * run sends the warning once a week until it is solved.
 */
final class ProviderWatch
{
    public const int AUTH_DAYS = 7;

    public const int VERSION_DAYS = 30;

    /**
     * @return list<array{key: string, kind: string, name: string, detail: string, tone: string, days: ?int, date: ?string, url: ?string, action: ?string}>
     */
    public function rows(): array
    {
        $rows = [];
        foreach (CoreIntegration::query()->orderBy('provider')->orderBy('id')->get() as $integration) {
            $config = is_array($integration->config) ? $integration->config : [];
            $expires = $config['refresh_token_expires_at'] ?? null;
            if ($expires === null) {
                // An access key that renews itself (it carries a refresh key) ends every hour and comes back: not a date to watch.
                $credential = CoreIntegrationCredential::query()->where('integration_id', $integration->id)
                    ->where('credential_type', CoreIntegrationCredential::TYPE_AUTHORIZATION)->orderByDesc('id')->first();
                $expires = filled(((array) $credential?->encrypted_payload)['refresh_token'] ?? null) ? null : $credential?->expires_at;
            }
            $label = match ((string) $integration->provider) {
                'google' => 'Google yetkisi', 'meta' => 'Meta yetkisi', 'openai' => 'OpenAI yetkisi', 'whatsapp' => 'WhatsApp yetkisi',
                default => ucfirst(str_replace('_', ' ', (string) $integration->provider)).' yetkisi',
            };
            $url = match ((string) $integration->provider) {
                'google' => route('operator.integrations.google'), 'meta' => route('operator.integrations.meta'), default => route('operator.integrations'),
            };
            $rows[] = $this->row('auth:'.$integration->id, 'auth', filled($integration->name) ? trim((string) $integration->name).' yetkisi' : $label, $this->date($expires), self::AUTH_DAYS, $url,
                'Yetki süresiz ya da bitiş tarihi bildirilmemiş; koparsa Eksikler görünümüne düşer.', 'Yetki bitince veri çekimi durur. Yeniden bağlamak bir dakika sürer.', 'Yeniden bağla');
        }
        $sunsets = (array) config('provider-watch');
        $versions = [
            ['google_ads', 'Google Ads API', (string) config('moxdop.google.ads_api_version', '')],
            ['meta_marketing', 'Meta reklam API', (string) config('moxdop-meta-ads-collector.api_version', '')],
            ['meta_graph', 'Meta Graph API', (string) config('moxdop.meta.api_version', (string) config('moxdop-meta-ads-collector.api_version', ''))],
            ['meta_graph', 'WhatsApp (Graph API)', (string) config('whatsapp.graph_version', '')],
        ];
        foreach ($versions as [$family, $name, $version]) {
            if ($version === '') {
                continue;
            }
            $known = array_key_exists($version, (array) ($sunsets[$family] ?? []));
            $rows[] = $this->row('version:'.$name, 'version', $name.' '.$version, $known ? $this->date($sunsets[$family][$version]) : null, self::VERSION_DAYS, null,
                $known ? 'Sağlayıcı bu sürümün kapanış tarihini henüz açıklamadı.' : 'Bu sürümün kapanış tarihi listede yok; liste güncellenmeli.',
                'Sürüm kapanınca bu kanalın veri çekimi durur. Sürüm yükseltmesi sistemin işi; geliştirme havuzuna düşer.', null);
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> the rows that need a word today */
    public function due(): array
    {
        return array_values(array_filter($this->rows(), fn (array $row): bool => in_array($row['tone'], ['warn', 'bad'], true)));
    }

    /** The daily run: one push per due row, at most once a week each. Returns how many were sent. */
    public function notify(PushNotifier $push): int
    {
        $sent = 0;
        foreach ($this->due() as $row) {
            $sent += $push->send('provider-watch:'.$row['key'].':'.$row['date'], $row['name'].': '.($row['days'] < 0 ? 'süresi doldu' : $row['days'].' gün kaldı'),
                $row['detail'], 'high', route('operator.integrations.connection-health', ['gorunum' => 'nobet']), 24 * 7) > 0 ? 1 : 0;
        }

        return $sent;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        try {
            return filled($value) ? CarbonImmutable::parse((string) $value, 'Europe/Istanbul')->startOfDay() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{key: string, kind: string, name: string, detail: string, tone: string, days: ?int, date: ?string, url: ?string, action: ?string} */
    private function row(string $key, string $kind, string $name, ?CarbonImmutable $ends, int $warnDays, ?string $url, string $unknown, string $consequence, ?string $action): array
    {
        if ($ends === null) {
            return ['key' => $key, 'kind' => $kind, 'name' => $name, 'detail' => $unknown, 'tone' => 'muted', 'days' => null, 'date' => null, 'url' => null, 'action' => null];
        }
        $days = (int) CarbonImmutable::now('Europe/Istanbul')->startOfDay()->diffInDays($ends, false);
        $tone = $days < 0 ? 'bad' : ($days <= $warnDays ? 'warn' : 'ok');
        $when = $ends->locale('tr')->isoFormat('D MMMM YYYY');

        return ['key' => $key, 'kind' => $kind, 'name' => $name, 'tone' => $tone, 'days' => $days, 'date' => $ends->toDateString(), 'url' => $tone === 'ok' ? null : $url, 'action' => $tone === 'ok' ? null : $action,
            'detail' => match ($tone) {
                'bad' => $when.' tarihinde bitti. '.$consequence,
                'warn' => $when.' tarihinde bitiyor, '.$days.' gün kaldı. '.$consequence,
                default => $when.' tarihine kadar geçerli ('.$days.' gün).',
            }];
    }
}

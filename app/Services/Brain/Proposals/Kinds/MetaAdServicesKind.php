<?php

namespace App\Services\Brain\Proposals\Kinds;

use App\Ai\Agents\Brain\CreativeClassifierAgent;
use App\Models\BrainProposal;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Brain\BrainAi;
use App\Services\Brain\Proposals\ProposalKind;
use App\Services\Brain\Proposals\ProposalService;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\DB;

/**
 * "Meta reklamı → hizmet": AI files the ads the name rules could not place under a service and labels every ad's
 * message angle. One proposal per Meta account; approving writes the choices (source "ai").
 */
final class MetaAdServicesKind implements ProposalKind
{
    public const string KIND = 'meta_ad_services';

    public const array ANGLE_LABELS = ['price_offer' => 'Fiyat / kampanya', 'trust_expertise' => 'Güven / uzmanlık', 'result_benefit' => 'Sonuç / fayda', 'pain_problem' => 'Sorun / endişe',
        'social_proof' => 'Sosyal kanıt', 'education' => 'Bilgilendirme', 'urgency' => 'Aciliyet', 'other' => 'Diğer'];

    public function __construct(
        private readonly BrainAi $ai,
        private readonly ProposalService $proposals,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function label(): string
    {
        return 'Meta reklamı → hizmet ve mesaj';
    }

    public function usesAi(): bool
    {
        return true;
    }

    public function resultNoun(): string
    {
        return 'yeni öneri';
    }

    public function prepare(array $options): int
    {
        $assetIds = DB::table('brain_meta_ads')->where(fn ($q) => $q->whereNull('angle')->orWhereNull('service_id'))
            ->when(! empty($options['brand_id']), fn ($q) => $q->where('brand_id', (int) $options['brand_id']))
            ->distinct()->limit(20)->pluck('digital_asset_id');
        $created = 0;
        foreach ($assetIds as $assetId) {
            $asset = DigitalAsset::query()->with('brand')->find($assetId);
            if ($asset === null) {
                continue;
            }
            $ads = DB::table('brain_meta_ads')->where('digital_asset_id', $asset->id)->where(fn ($q) => $q->whereNull('angle')->orWhereNull('service_id'))
                ->orderByDesc('spend')->limit(60)->get();
            $creatives = DB::table('meta_creative_snapshot')->whereIn('creative_id', $ads->pluck('creative_id')->filter())->get(['creative_id', 'metadata'])
                ->mapWithKeys(fn ($r): array => [(string) $r->creative_id => (array) json_decode((string) $r->metadata, true)]);
            $services = DB::table('brand_offerings as o')->join('service_catalog_names as n', function ($join): void {
                $join->on('n.service_catalog_item_id', '=', 'o.service_catalog_item_id')->where('n.is_primary', true);
            })->where('o.brand_id', $asset->brand_id)->where('o.status', 'active')->get(['o.service_catalog_item_id as id', 'n.raw_label as name']);
            if ($ads->isEmpty() || $services->isEmpty()) {
                continue;
            }
            $answer = $this->ai->ask(new CreativeClassifierAgent, AiRouteKeys::BRAIN_CREATIVE_CLASSIFIER, [
                'services' => $services->map(fn ($s): array => ['id' => (int) $s->id, 'name' => (string) $s->name])->values()->all(),
                'ads' => $ads->map(fn ($ad): array => [
                    'id' => (string) $ad->ad_id, 'name' => $ad->ad_name,
                    'title' => mb_substr((string) ($creatives[$ad->creative_id]['title'] ?? ''), 0, 200),
                    'text' => mb_substr((string) ($creatives[$ad->creative_id]['body'] ?? ''), 0, 600),
                ])->values()->all(),
            ]);
            $allowed = $services->pluck('id')->map('intval')->all();
            $choices = [];
            foreach ((array) ($answer['items'] ?? []) as $item) {
                $ad = $ads->firstWhere('ad_id', (string) ($item['ad_id'] ?? ''));
                if ($ad === null || ! in_array($item['angle'] ?? null, CreativeClassifierAgent::ANGLES, true)) {
                    continue;
                }
                $serviceId = in_array((int) ($item['service_id'] ?? 0), $allowed, true) ? (int) $item['service_id'] : null;
                $choices[(string) $ad->ad_id] = ['service_id' => $ad->service_id !== null ? (int) $ad->service_id : $serviceId, 'angle' => $item['angle'], 'name' => $ad->ad_name];
            }
            if ($choices === []) {
                continue;
            }
            $serviceNames = $services->pluck('name', 'id');
            $created += $this->proposals->propose(self::KIND, 'digital_asset', (int) $asset->id, $asset->brand_id,
                ($asset->brand?->name ?? $asset->name).' · '.count($choices).' Meta reklamı sınıflandı', null,
                ['ads' => $choices, 'items' => array_values(array_map(fn (array $c): string => $c['name'].' → '.($c['service_id'] !== null ? $serviceNames[$c['service_id']] : 'hizmet yok').' · '.self::ANGLE_LABELS[$c['angle']], $choices))],
                'Reklam adı, reklam seti / kampanya adı ve kreatif metnine göre.', null, 'ai') !== null ? 1 : 0;
        }

        return $created;
    }

    public function apply(BrainProposal $proposal, User $actor): void
    {
        foreach ((array) $proposal->proposed['ads'] as $adId => $choice) {
            DB::table('brain_meta_ads')->where('digital_asset_id', $proposal->subject_id)->where('ad_id', (string) $adId)
                ->update(['service_id' => $choice['service_id'], 'angle' => $choice['angle'], 'source' => 'ai', 'confidence' => null, 'updated_at' => now()]);
        }
    }
}

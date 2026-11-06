<?php

namespace App\Services\Site\Backlinks;

use App\Ai\Agents\Site\LinkPackAgent;
use App\Jobs\Site\WriteLinkPack;
use App\Models\Backlink;
use App\Models\BacklinkSource;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\AiTasks\AiWorkers;
use App\Services\Brand\BrandPack;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Sales\SalesSubscription;
use App\Services\Site\SiteDomains;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Başvuru malzemesi of a Bağlantı masası row (Backlink hizmeti, 2026-11-06): profile texts, form values, e-mail, phone
 * note. Bulk work, so the subscription Claude writes it in its next round when it is connected; otherwise the Claude
 * API route at once. A source Claude finds unsafe or unrelated (fit false) is set aside with the reason, never offered.
 * Nothing is sent here.
 */
final class LinkPackWriter
{
    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly SalesSubscription $subscription,
        private readonly AiTaskQueue $queue,
        private readonly AiWorkers $workers,
        private readonly GbpDesk $gbp,
    ) {}

    public function write(int $rowId, ?WriteLinkPack $job = null): bool
    {
        $row = BacklinkSource::query()->with(['brand.customer', 'pool', 'target'])->find($rowId);
        if ($row === null || $row->status !== BacklinkSource::NONE || ! $row->brand instanceof Brand || ! $row->brand->isOperational()) {
            return false;
        }
        if ($job !== null && ($answer = $this->subscription->answered(new WriteLinkPack($rowId), LinkPackAgent::ROUTE)) !== null) {
            if ($answer === []) {
                $this->fail($row, 'Abonelikteki Claude yazamadı; "Yeniden yaz" ile tekrar dene.');

                return false;
            }

            return $this->store($row, $answer);
        }
        $payload = $this->payload($row);
        if ($this->subscription->canWait()) {
            return $this->wait($row, $payload);
        }
        $row->forceFill(['material_status' => 'running'])->save();
        try {
            $route = $this->routes->resolve(LinkPackAgent::ROUTE);
            if ($route->isEmpty()) {
                $this->fail($row, 'Yazacak yapay zekâ bağlı değil (Ayarlar › AI).');

                return false;
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $answer = (new LinkPackAgent)->prompt("PACK_JSON\n".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels, timeout: 150)->toArray();

            return $this->store($row, (array) $answer);
        } catch (Throwable $exception) {
            Log::warning('Link pack failed.', ['row' => $row->id, 'error' => $exception->getMessage()]);
            $this->fail($row, 'Yapay zekâ isteği başarısız oldu; "Yeniden yaz" ile tekrar dene.');

            return false;
        }
    }

    /** @param  array<string, mixed>  $answer */
    public function store(BacklinkSource $row, array $answer): bool
    {
        $valid = Validator::make($answer, [
            'fit' => ['required', 'boolean'], 'why_not' => ['present', 'nullable', 'string', 'max:500'],
            'short_description' => ['present', 'nullable', 'string', 'max:400'], 'description' => ['present', 'nullable', 'string', 'max:2000'],
            'fields' => ['present', 'array', 'max:20'], 'fields.*.label' => ['required', 'string', 'max:80'], 'fields.*.value' => ['present', 'nullable', 'string', 'max:2000'],
            'email_subject' => ['present', 'nullable', 'string', 'max:200'], 'email_body' => ['present', 'nullable', 'string', 'max:4000'],
            'phone_note' => ['present', 'nullable', 'string', 'max:1000'], 'steps' => ['present', 'array', 'max:6'], 'steps.*' => ['string', 'max:300'],
        ]);
        if ($valid->fails()) {
            $this->fail($row, 'Yapay zekâ geçersiz bir cevap döndürdü; "Yeniden yaz" ile tekrar dene.');

            return false;
        }
        if (! (bool) $answer['fit']) {
            $why = trim((string) $answer['why_not']) ?: 'Bu marka için uygun bulunmadı.';
            $row->forceFill(['status' => BacklinkSource::DROPPED, 'material_status' => 'ready', 'material' => null, 'material_note' => mb_substr('Claude: '.$why, 0, 990)])->save();

            return true;
        }
        $fields = collect((array) $answer['fields'])->map(fn (array $f): array => ['label' => trim((string) $f['label']), 'value' => trim((string) ($f['value'] ?? ''))])
            ->filter(fn (array $f): bool => $f['label'] !== '' && $f['value'] !== '')->values()->all();
        $row->forceFill(['material_status' => 'ready', 'material_note' => null, 'material' => [
            'short' => trim((string) $answer['short_description']), 'description' => trim((string) $answer['description']), 'fields' => $fields,
            'email_subject' => trim((string) $answer['email_subject']), 'email_body' => trim((string) $answer['email_body']),
            'phone_note' => trim((string) $answer['phone_note']), 'steps' => array_values(array_filter(array_map('trim', (array) $answer['steps']))),
            'written_at' => now()->toIso8601String(),
            ...(filled(data_get($row->material, 'to')) ? ['to' => data_get($row->material, 'to')] : []), // the address the operator / the page gave
        ]])->save();

        return true;
    }

    /** @param  array<string, mixed>  $payload */
    private function wait(BacklinkSource $row, array $payload): bool
    {
        $job = new WriteLinkPack((int) $row->id);
        $this->queue->begin($job, (int) $row->brand_id, 'Bağlantı başvurusu: '.$row->name);
        try {
            $this->queue->answer(new LinkPackAgent, $payload, 'pack', 'PACK_JSON');
        } finally {
            $this->queue->settle();
        }
        $round = $this->workers->nextRound(AiWorkers::CLAUDE_MCP);
        $row->forceFill(['material_status' => 'waiting', 'material_note' => 'Abonelikteki Claude '
            .($round !== null ? ($round->isToday() ? 'bugün ' : 'yarın ').$round->format('H:i').' turunda' : 'bir sonraki turunda').' yazacak.'])->save();

        return false;
    }

    private function fail(BacklinkSource $row, string $reason): void
    {
        $row->forceFill(['material_status' => 'failed', 'material_note' => $reason])->save();
    }

    /** @return array<string, mixed> */
    public function payload(BacklinkSource $row): array
    {
        $brand = $row->brand;
        $profiles = DigitalAsset::query()->where('brand_id', $brand->id)->whereIn('type', ['gbp', 'google_business_profile'])->where('status', '!=', 'archived')
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $profile = array_values($this->gbp->snapshots($profiles))[0] ?? null;
        $own = SiteDomains::ownDomains($brand);
        $site = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->where('status', 'active')->orderBy('id')->first();
        $lost = $row->engine === 'geri_kazanim'
            ? Backlink::query()->where('brand_id', $brand->id)->where('source_domain', $row->domain)->where('status', 'kaldirildi')->latest('lost_at')->first()
            : null;

        return [
            'brand' => [
                'name' => $brand->name,
                'website' => $site?->primary_url ?: ($own[0] ?? null),
                'sector' => $brand->sectorCategory?->name,
                'phone' => $profile['phone'] ?? null,
                'address' => $profile['address_text'] ?? null,
                'area' => $profile['area'] ?? null,
                'hours' => self::hours((array) ($profile['regular_hours'] ?? [])),
                'profile_description' => $profile['description'] ?? null,
                'category' => $profile['primary_category'] ?? null,
                'services' => BrandPack::serviceNames($brand, 15),
                'areas' => BrandPack::areaNames($brand),
                'experts' => array_slice(BrandPack::card($brand, ['experts'])['experts'] ?? [], 0, 5),
            ],
            'source' => [
                'name' => $row->name, 'url' => $row->url, 'kind' => $row->pool?->kindLabel() ?? $row->kind,
                'apply_via' => $row->pool?->apply_via ?? 'bilinmiyor', 'fee' => $row->fee, 'reason' => $row->reason,
            ],
            'engine' => $row->engine,
            'target' => ['url' => $row->target?->url ?? ($site?->primary_url ?: null), 'title' => $row->target?->title, 'anchor' => $row->anchor ?: $brand->name],
            'data_story' => $row->engine === 'veri' ? ['questions' => LinkDataStory::questions($brand), 'days' => LinkDataStory::DAYS] : null,
            'lost_link' => $lost !== null ? ['page' => $lost->source_url, 'old_target' => $lost->target_url, 'old_anchor' => $lost->anchor] : null,
            'rules' => BrandPack::rules($brand),
        ];
    }

    /** @param  list<array<string, mixed>>  $periods */
    private static function hours(array $periods): ?string
    {
        $days = ['MONDAY' => 'Pzt', 'TUESDAY' => 'Sal', 'WEDNESDAY' => 'Çar', 'THURSDAY' => 'Per', 'FRIDAY' => 'Cum', 'SATURDAY' => 'Cmt', 'SUNDAY' => 'Paz'];
        $out = [];
        foreach ($periods as $p) {
            $day = $days[(string) ($p['openDay'] ?? '')] ?? null;
            if ($day === null) {
                continue;
            }
            $time = fn (mixed $t): string => sprintf('%02d:%02d', (int) data_get($t, 'hours', 0), (int) data_get($t, 'minutes', 0));
            $out[] = $day.' '.$time($p['openTime'] ?? []).'-'.$time($p['closeTime'] ?? []);
        }

        return $out !== [] ? implode(', ', $out) : null;
    }
}

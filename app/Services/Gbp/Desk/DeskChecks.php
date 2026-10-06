<?php

namespace App\Services\Gbp\Desk;

use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpQueuedPost;
use App\Services\Gbp\GbpDailyWorkspace;
use App\Services\Gbp\GbpPostQueue;
use Illuminate\Support\Collection;

/**
 * The six İşletme profilleri checks of each profile (branch page, description, holiday hours, photos, reviews, post
 * plan) and the desk's writes on a profile. Shared by "Durum ve ölçüm" and the profile's own asset page, so both show
 * the same state.
 */
final class DeskChecks
{
    /** Route of the desk tab where each check is fixed. */
    public const array ROUTES = ['page' => 'operator.gbp-branch-pages', 'description' => 'operator.gbp-profile-fields', 'hours' => 'operator.gbp-profile-fields',
        'photos' => 'operator.gbp-photos', 'reviews' => 'operator.gbp-reviews', 'posts' => 'operator.gbp-posts'];

    public function __construct(
        private readonly GbpDesk $desk,
        private readonly BranchPages $branches,
        private readonly ProfileFields $fields,
        private readonly PhotoPlan $photos,
        private readonly ReviewDesk $reviews,
        private readonly GbpDailyWorkspace $daily,
    ) {}

    /**
     * @param  Collection<int, DigitalAsset>  $locations  GbpDesk::locations() rows
     * @return array{rows: array<int, array{checks: array<string, array{ok: bool, label: string, hint: string, route: string}>, score: int, snapshot: ?array<string, mixed>, resource_id: ?int}>, holiday: ?array<string, mixed>, resources: array<int, int>}
     */
    public function rows(Collection $locations): array
    {
        $ids = $locations->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $snapshots = $this->desk->snapshots($ids);
        $resources = $this->daily->resourceIds($ids);
        $pages = $this->branches->states($locations, $snapshots);
        $descriptions = $this->fields->descriptions($locations, $snapshots);
        $holiday = $this->fields->holidays()[0] ?? null;
        $hours = $holiday !== null ? $this->fields->holidayState($snapshots, $holiday['dates']) : [];
        $photoStatus = $this->photos->status($resources);
        $reviewStats = $this->reviews->stats($resources);
        $today = GbpPostQueue::today();
        $planned = $ids === [] ? collect() : GbpQueuedPost::query()->whereIn('digital_asset_id', $ids)->whereIn('status', [GbpQueuedPost::DRAFT, GbpQueuedPost::APPROVED, GbpQueuedPost::PUBLISHED])
            ->whereBetween('publish_on', [$today->addDay()->toDateString(), $today->addDays(GbpPostQueue::HORIZON_DAYS)->toDateString()])
            ->selectRaw('digital_asset_id, count(*) as n')->groupBy('digital_asset_id')->pluck('n', 'digital_asset_id');

        $rows = [];
        foreach ($locations as $location) {
            $id = (int) $location->id;
            $checks = [
                'page' => ['ok' => in_array($pages[$id]['state'] ?? '', BranchPages::DONE, true), 'label' => 'Şube sayfası', 'hint' => $pages[$id]['label'] ?? ''],
                'description' => ['ok' => ($descriptions[$id]['state'] ?? '') === 'ok', 'label' => 'Açıklama', 'hint' => $descriptions[$id]['label'] ?? ''],
                'hours' => ['ok' => $holiday === null || ($hours[$id]['missing'] ?? ['x']) === [], 'label' => 'Özel gün saatleri', 'hint' => $holiday !== null ? $holiday['name'].(($hours[$id]['missing'] ?? ['x']) === [] ? ' girildi' : ' saatleri girilmedi') : 'Yaklaşan resmi tatil yok'],
                'photos' => ['ok' => ! ($photoStatus[$id]['stale'] ?? true), 'label' => 'Fotoğraf', 'hint' => isset($photoStatus[$id]['days']) && $photoStatus[$id]['days'] !== null ? 'Son fotoğraf '.$photoStatus[$id]['days'].' gün önce' : 'Fotoğraf bilgisi yok'],
                'reviews' => ['ok' => ($reviewStats[$id]['unanswered'] ?? 0) === 0, 'label' => 'Yorumlar', 'hint' => ($reviewStats[$id]['unanswered'] ?? 0) > 0 ? $reviewStats[$id]['unanswered'].' yanıtsız yorum' : 'Yanıtsız yorum yok'],
                'posts' => ['ok' => (int) ($planned[$id] ?? 0) >= 15, 'label' => 'Gönderi planı', 'hint' => (int) ($planned[$id] ?? 0).'/'.GbpPostQueue::HORIZON_DAYS.' gün planlı'],
            ];
            foreach ($checks as $key => $check) {
                $checks[$key]['route'] = self::ROUTES[$key];
            }
            $rows[$id] = ['checks' => $checks, 'score' => count(array_filter($checks, fn (array $c): bool => $c['ok'])), 'snapshot' => $snapshots[$id] ?? null,
                'resource_id' => $resources[$id] ?? null];
        }

        return ['rows' => $rows, 'holiday' => $holiday, 'resources' => $resources];
    }

    /**
     * What the desk sent to Google for a profile (newest first): posts, photos, review replies, profile fields,
     * categories / services.
     *
     * @return list<array{id: int, kind: string, label: string, status: string, status_label: string, at: ?string, by: string, error: ?string}>
     */
    public function history(int $assetId, int $limit = 15): array
    {
        return ExternalWriteAction::query()->with('requester:id,name')->where('digital_asset_id', $assetId)->whereIn('action', array_keys(GbpPerformance::WORK))
            ->latest('id')->limit($limit)->get()
            ->map(fn (ExternalWriteAction $a): array => ['id' => (int) $a->id, 'kind' => GbpPerformance::WORK[$a->action], 'label' => (string) (data_get($a->request_payload, 'label') ?: GbpPerformance::WORK[$a->action]),
                'status' => (string) $a->status, 'status_label' => $a->statusLabel(), 'at' => ($a->finished_at ?? $a->created_at)?->timezone('Europe/Istanbul')->format('d.m.Y H:i'),
                'by' => (string) ($a->requester?->name ?? ''), 'error' => $a->status === 'failed' ? mb_substr((string) $a->error, 0, 200) : null])
            ->all();
    }
}

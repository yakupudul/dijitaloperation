<?php

namespace App\Services\Compliance;

use App\Models\Brand;
use App\Models\ComplianceRule;
use App\Services\BrandSetup\BrandSetupMatcher;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sector packs from config/moxdop-sector-packs.php, their on/off state and their rules. Pack default rules
 * are copied into compliance_rules once (syncDefaults); edits there win over the pack's defaults.
 */
final class SectorPackRegistry
{
    /** Rules of one sector without a pack (Sorgular › Yasaklı ifadeler): pack_id "sector:{code}". */
    public const string SECTOR_PREFIX = 'sector:';

    /** Brand-only rules (Marka › Ayarlar): pack_id "brand:{id}". */
    public const string BRAND_PREFIX = 'brand:';

    /** In a rule's phrase: the brand's own name (and domain), filled per brand — "{marka}" forbids naming the brand. */
    public const string BRAND_TOKEN = '{marka}';

    /** @var array<string, SectorPack>|null */
    private ?array $packs = null;

    /** @return array<string, SectorPack> */
    public function all(): array
    {
        if ($this->packs === null) {
            $this->packs = [];
            foreach ((array) config('moxdop-sector-packs.packs', []) as $class) {
                $pack = app($class);
                if (! $pack instanceof SectorPack) {
                    throw new InvalidArgumentException($class.' is not a SectorPack.');
                }
                $this->packs[$pack->id()] = $pack;
            }
        }

        return $this->packs;
    }

    public function get(string $id): ?SectorPack
    {
        return $this->all()[$id] ?? null;
    }

    public function isEnabled(string $id): bool
    {
        return (bool) (DB::table('sector_pack_settings')->where('pack_id', $id)->value('enabled') ?? true);
    }

    public function setEnabled(string $id, bool $enabled, ?int $userId): void
    {
        DB::table('sector_pack_settings')->updateOrInsert(['pack_id' => $id], ['enabled' => $enabled, 'updated_by' => $userId, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** Enabled packs that apply to the brand's sectors. @return list<SectorPack> */
    public function forBrand(Brand $brand): array
    {
        $codes = $brand->sectorCodes();

        return array_values(array_filter($this->all(), fn (SectorPack $pack): bool => $this->isEnabled($pack->id()) && array_intersect($codes, $pack->sectorCodes()) !== []));
    }

    /** Copy missing default rules of every pack (never touches existing rows). */
    public function syncDefaults(): int
    {
        $created = 0;
        foreach ($this->all() as $pack) {
            $existing = ComplianceRule::query()->where('pack_id', $pack->id())->pluck('rule_key')->flip();
            foreach ($pack->defaultRules() as $rule) {
                if (isset($existing[$rule['rule_key']])) {
                    continue;
                }
                ComplianceRule::query()->create([
                    'pack_id' => $pack->id(), 'rule_key' => $rule['rule_key'], 'kind' => $rule['kind'], 'label' => $rule['label'],
                    'patterns' => $rule['patterns'], 'message' => $rule['message'], 'severity' => $rule['severity'],
                    'applies_to' => $rule['applies_to'] ?? null, 'active' => $rule['active'] ?? true, 'origin' => 'pack',
                ]);
                $created++;
            }
        }

        return $created;
    }

    /**
     * Active rules for the brand: its enabled sector packs, its sectors' own rules and its brand-only rules.
     *
     * @return Collection<int, ComplianceRule>
     */
    public function rulesForBrand(Brand $brand): Collection
    {
        $packIds = array_map(fn (SectorPack $pack): string => $pack->id(), $this->forBrand($brand));
        if ($packIds !== []) {
            $this->syncDefaults();
        }
        $scopes = [...$packIds, ...array_map(fn (string $code): string => self::SECTOR_PREFIX.$code, $brand->sectorCodes()), self::BRAND_PREFIX.$brand->id];
        $marks = null;

        return ComplianceRule::query()->whereIn('pack_id', $scopes)->where('active', true)->orderBy('id')->get()
            ->each(function (ComplianceRule $rule) use ($brand, &$marks): void {
                if (! self::hasBrandToken($rule)) {
                    return;
                }
                $marks ??= self::brandMarks($brand);
                $rule->patterns = array_values(array_unique(collect((array) $rule->patterns)
                    ->flatMap(fn (mixed $pattern): array => str_contains((string) $pattern, self::BRAND_TOKEN)
                        ? array_map(fn (string $mark): string => str_replace(self::BRAND_TOKEN, $mark, (string) $pattern), $marks)
                        : [(string) $pattern])->all()));
            });
    }

    public static function hasBrandToken(ComplianceRule $rule): bool
    {
        return collect((array) $rule->patterns)->contains(fn (mixed $pattern): bool => str_contains((string) $pattern, self::BRAND_TOKEN));
    }

    /**
     * What "{marka}" stands for in a rule ("İçerikte marka adı geçmesin", yakup 2026-10-02): the brand's name and the
     * distinctive part of its websites' domains ("adadent.com.tr" → "adadent"), at least 3 letters.
     *
     * @return list<string>
     */
    public static function brandMarks(Brand $brand): array
    {
        // A domain root that is a service word ("implant.com.tr") would forbid the service itself: it stays out.
        $serviceWords = $brand->offerings()->with('catalogItem.names')->get()
            ->flatMap(fn ($offering): array => $offering->catalogItem?->names->pluck('raw_label')->all() ?? [])
            ->flatMap(fn (mixed $name): array => preg_split('/[^\p{L}\p{N}]+/u', SeoText::fold((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [])->unique()->values()->all();
        $hosts = $brand->digitalAssets()->where('type', 'website')->pluck('domain')->filter()
            ->map(fn (string $host): string => BrandSetupMatcher::domainRoot(mb_strtolower(preg_replace('/^www\./', '', $host) ?? $host)))
            ->reject(fn (string $root): bool => collect($serviceWords)->contains(fn (string $word): bool => mb_strlen($word) >= 4
                && (str_starts_with($word, SeoText::fold($root)) || str_starts_with(SeoText::fold($root), $word))));

        return collect([trim((string) $brand->name), ...$hosts->all()])
            ->filter(fn (string $mark): bool => mb_strlen($mark) >= 3)->unique(fn (string $mark): string => mb_strtolower($mark))->values()->all();
    }

    /**
     * Rules of one sector (no brand): enabled packs covering the sector code and the sector's own rules.
     *
     * @return Collection<int, ComplianceRule>
     */
    public function rulesForSector(string $code, bool $activeOnly = true): Collection
    {
        $packIds = array_values(array_map(fn (SectorPack $pack): string => $pack->id(),
            array_filter($this->all(), fn (SectorPack $pack): bool => $this->isEnabled($pack->id()) && in_array($code, $pack->sectorCodes(), true))));
        if ($packIds !== []) {
            $this->syncDefaults();
        }

        return ComplianceRule::query()->whereIn('pack_id', [...$packIds, self::SECTOR_PREFIX.$code])
            ->when($activeOnly, fn ($q) => $q->where('active', true))->orderBy('id')->get();
    }
}

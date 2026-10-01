<?php

namespace App\Services\Compliance;

use App\Models\Brand;
use App\Models\ComplianceRule;
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

        return ComplianceRule::query()->whereIn('pack_id', $scopes)->where('active', true)->orderBy('id')->get();
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

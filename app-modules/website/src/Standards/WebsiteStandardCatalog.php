<?php

namespace MoxDop\Website\Standards;

use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class WebsiteStandardCatalog
{
    public const string VERSION = 'website-standards-v4';

    public const array GROUPS = [
        'access' => 'Erişim ve indeksleme',
        'structure' => 'Site yapısı ve bağlantılar',
        'metadata' => 'Başlıklar ve sayfa bilgileri',
        'coverage' => 'Hizmet ve arama ihtiyacı',
        'content' => 'İçerik yeterliliği',
        'local' => 'Yerel hizmet bilgileri',
        'trust' => 'Güven ve özgün kanıt',
        'conversion' => 'Kullanıcı yolculuğu',
        'ai' => 'Arşivlenmiş uzman kriterleri',
        'health' => 'WordPress sağlık',
        'updates' => 'Sürüm ve güncelleme',
        'security' => 'Güvenlik',
        'performance' => 'Hız ve kaynaklar',
        'media' => 'Görsel SEO',
        'compliance' => 'Sağlık tanıtım mevzuatı',
        'measurement' => 'Ölçüm',
        'gbp_profile' => 'Profil bilgileri',
        'gbp_activity' => 'Profil etkinliği',
        'gbp_reviews' => 'Yorumlar',
    ];

    /** Asset types with standards; each tab of /library/website-standards. */
    public const array ASSET_TYPES = ['website' => 'Web sitesi', 'google_business_profile' => 'İşletme Profili'];

    /**
     * Enabled standards of one asset type (website standards have no asset_type on custom criteria).
     *
     * @return array<string, array<string, mixed>>
     */
    public function forAssetType(string $assetType, bool $enabledOnly = true): array
    {
        return array_filter($this->all($enabledOnly), fn (array $definition): bool => ($definition['asset_type'] ?? 'website') === $assetType);
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $data = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/standards.json'), true, 512, JSON_THROW_ON_ERROR);

        return collect($data['standards'])->keyBy('id')->all();
    }

    /** @return array<string, array<string, mixed>> */
    public function all(bool $enabledOnly = false): array
    {
        $definitions = $this->definitions();
        foreach (DB::table('website_standard_settings')->orderBy('standard_id')->get() as $setting) {
            $stored = self::isStoredDefinition((string) $setting->standard_id);
            if ($setting->custom_definition !== null && $stored) {
                $definitions[$setting->standard_id] = json_decode($setting->custom_definition, true, 512, JSON_THROW_ON_ERROR);
                if (str_starts_with((string) $setting->standard_id, self::DECISION_PREFIX)) {
                    // Faz 4a: a standard proposed from a decision applies only where its scope matches.
                    $definitions[$setting->standard_id] = array_merge($definitions[$setting->standard_id], [
                        'scope_type' => $setting->scope_type ?? 'general',
                        'scope_id' => $setting->scope_id !== null ? (int) $setting->scope_id : null,
                        'version' => (int) ($setting->version ?? 1),
                        'created_from_suggestion_id' => $setting->created_from_suggestion_id !== null ? (int) $setting->created_from_suggestion_id : null,
                    ]);
                }
            }
            if (isset($definitions[$setting->standard_id])) {
                $definitions[$setting->standard_id]['enabled'] = (bool) $setting->enabled;
                if (! $stored && $setting->custom_definition !== null) {
                    $overrides = json_decode($setting->custom_definition, true);
                    if (in_array($overrides['severity'] ?? null, ['low', 'medium', 'high'], true)) {
                        $definitions[$setting->standard_id]['severity'] = $overrides['severity'];
                    }
                }
            }
        }
        foreach ($definitions as &$definition) {
            if ($definition['method'] === 'expert_review') {
                $definition['enabled'] = false;
            }
        }
        unset($definition);
        ksort($definitions);

        return array_filter($definitions, fn (array $definition): bool => ! $enabledOnly || $definition['enabled']);
    }

    /** Faz 4a: standards proposed from a decision ("Bu karardan standart öner"), stored with a scope. */
    public const string DECISION_PREFIX = 'website:decision:';

    public static function isStoredDefinition(string $id): bool
    {
        return str_starts_with($id, 'website:custom:') || str_starts_with($id, self::DECISION_PREFIX);
    }

    /**
     * Keeps only the standards whose scope matches: unscoped / general ones always, sector ones for that sector, brand
     * ones for that brand, URL ones for that page.
     *
     * @param  array<string, array<string, mixed>>  $definitions
     * @param  list<int>  $pageIds
     * @return array<string, array<string, mixed>>
     */
    public static function applicable(array $definitions, ?int $brandId, ?int $sectorId, array $pageIds = []): array
    {
        return array_filter($definitions, fn (array $d): bool => match ($d['scope_type'] ?? 'general') {
            'sector' => $sectorId !== null && (int) ($d['scope_id'] ?? 0) === $sectorId,
            'brand' => $brandId !== null && (int) ($d['scope_id'] ?? 0) === $brandId,
            'url' => in_array((int) ($d['scope_id'] ?? 0), $pageIds, true),
            default => true,
        });
    }

    /** @return list<array<string, mixed>> */
    public function expertCriteria(): array
    {
        return array_values(array_filter($this->all(true), fn (array $row): bool => $row['method'] === 'expert_review'));
    }

    /** @param array<string, array<string, mixed>> $definitions */
    public function fingerprint(array $definitions): string
    {
        return hash('sha256', json_encode([self::VERSION, $definitions], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function setEnabled(string $id, bool $enabled, User $actor): void
    {
        abort_unless($actor->is_active && $actor->can(Permissions::ACCESS_APP) && $actor->hasRole(Roles::ADMIN), 403);
        $definition = $this->definitions()[$id] ?? null;
        abort_unless($definition && $definition['method'] !== 'expert_review', 404);
        DB::table('website_standard_settings')->updateOrInsert(['standard_id' => $id], [
            'enabled' => $enabled, 'updated_by' => $actor->id, 'updated_at' => now(),
        ]);
    }

    public function setSeverity(string $id, string $severity, User $actor): void
    {
        abort_unless($actor->is_active && $actor->can(Permissions::ACCESS_APP) && $actor->hasRole(Roles::ADMIN), 403);
        $definition = $this->all()[$id] ?? null;
        abort_unless($definition && $definition['method'] !== 'expert_review', 404);
        abort_unless(in_array($severity, ['low', 'medium', 'high'], true), 422);
        DB::table('website_standard_settings')->updateOrInsert(['standard_id' => $id], [
            'enabled' => $definition['enabled'],
            'custom_definition' => json_encode(['severity' => $severity], JSON_THROW_ON_ERROR),
            'updated_by' => $actor->id, 'updated_at' => now(),
        ]);
    }

    public function resetStandard(string $id, User $actor): void
    {
        abort_unless($actor->is_active && $actor->can(Permissions::ACCESS_APP) && $actor->hasRole(Roles::ADMIN), 403);
        $definition = $this->definitions()[$id] ?? null;
        abort_unless($definition && $definition['method'] !== 'expert_review', 404);
        DB::table('website_standard_settings')->updateOrInsert(['standard_id' => $id], [
            'enabled' => $definition['enabled'], 'custom_definition' => null,
            'updated_by' => $actor->id, 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $input */
    public function addExpertCriterion(array $input, User $actor): void
    {
        abort_unless($actor->is_active && $actor->can(Permissions::ACCESS_APP) && $actor->hasRole(Roles::ADMIN), 403);
        $values = Validator::make($input, [
            'title' => ['required', 'string', 'max:160'],
            'group' => ['required', Rule::in(array_keys(self::GROUPS))],
            'criterion' => ['required', 'string', 'min:20', 'max:1500'],
            'action' => ['required', 'string', 'min:10', 'max:1000'],
            'source_url' => ['nullable', 'url:http,https', 'max:500'],
        ])->validate();
        abort_if(DB::table('website_standard_settings')->where('standard_id', 'like', 'website:custom:%')->whereNotNull('custom_definition')->count() >= 30, 422, 'En fazla 30 ek uzman kriteri tanımlanabilir.');
        $id = 'website:custom:'.Str::uuid();
        $definition = [
            'id' => $id, 'version' => 1, 'enabled' => true, 'title' => $values['title'],
            'group' => $values['group'], 'method' => 'expert_review', 'classification' => 'agency_practice',
            'applicability' => 'content_page', 'required_evidence' => ['stored_html', 'cluster_context'],
            'criterion' => $values['criterion'], 'action' => $values['action'],
            'verification' => 'Güncellenmiş sayfanın saklı HTML sürümünü aynı kriterle yeniden inceleyin.',
            'source_url' => $values['source_url'] ?? null, 'source_reviewed_at' => now()->toDateString(),
            'severity' => 'medium',
        ];
        DB::table('website_standard_settings')->insert([
            'standard_id' => $id, 'enabled' => true,
            'custom_definition' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

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
    public const string VERSION = 'website-standards-v2';

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
    ];

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
            if ($setting->custom_definition !== null && str_starts_with($setting->standard_id, 'website:custom:')) {
                $definitions[$setting->standard_id] = json_decode($setting->custom_definition, true, 512, JSON_THROW_ON_ERROR);
            }
            if (isset($definitions[$setting->standard_id])) {
                $definitions[$setting->standard_id]['enabled'] = (bool) $setting->enabled;
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
        abort_unless(isset($this->all()[$id]), 404);
        DB::table('website_standard_settings')->updateOrInsert(['standard_id' => $id], [
            'enabled' => $enabled, 'updated_by' => $actor->id, 'updated_at' => now(),
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
        abort_if(DB::table('website_standard_settings')->whereNotNull('custom_definition')->count() >= 30, 422, 'En fazla 30 ek uzman kriteri tanımlanabilir.');
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

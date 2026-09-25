<?php

namespace App\Services\Brain;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Yöntem Kütüphanesi: the rule thresholds and word lists of the advisors, SEO tasks, alerts and demand
 * pipeline, edited on screen. Editable keys are the numeric and string-list leaves of the rule config files
 * (switches, queues, schedules and AI settings are not). A saved value overrides the config file at boot;
 * "Varsayılana dön" deletes the override. Rules can be switched off (`disabled_rules` lists).
 */
final class MethodLibrary
{
    /** Config file => [label, excluded top-level / nested prefixes]. */
    public const array FILES = [
        'moxdop-advisor' => ['Danışman (Google Ads, Meta, İşletme Profili, kanallar arası)', ['enabled', 'queue_connection', 'queue', 'schedule', 'digest', 'disabled_rules']],
        'moxdop-seo-tasks' => ['SEO Görevleri', ['enabled', 'queue', 'queue_connection', 'schedule', 'llm', 'disabled_rules', 'understanding']],
        'moxdop-alerts' => ['Uyarılar', ['enabled']],
        'moxdop-demand' => ['Talep hattı', ['schedule', 'serp.language_code', 'serp.weekly_time', 'compare.weekly_time']],
        'moxdop-brain' => ['Hizmet Beyni (yöntem motoru, ölçüm)', []],
    ];

    public const string CACHE_KEY = 'moxdop.method_settings.v1';

    /** @var array<string, mixed> config defaults captured before overrides */
    private static array $defaults = [];

    /** Apply saved overrides to the runtime config (called from AppServiceProvider::boot). */
    public static function boot(): void
    {
        try {
            $rows = Cache::rememberForever(self::CACHE_KEY, fn (): array => DB::table('method_settings')->pluck('value', 'config_key')->all());
        } catch (Throwable) {
            return; // table not migrated yet
        }
        foreach ($rows as $key => $json) {
            if (! self::isAllowed((string) $key)) {
                continue;
            }
            self::$defaults[$key] ??= config($key);
            config([$key => json_decode((string) $json, true)]);
        }
    }

    /**
     * Editable settings grouped by file and section.
     *
     * @return array<string, array{label: string, sections: array<string, list<array{key: string, name: string, type: string, value: mixed, default: mixed, overridden: bool}>>}>
     */
    public function catalog(): array
    {
        $out = [];
        $seen = [];
        foreach (self::FILES as $file => [$label]) {
            $sections = [];
            foreach (Arr::dot((array) $this->defaultsOf($file)) as $path => $value) {
                $key = $file.'.'.$path;
                // Lists are flattened by Arr::dot (a.b.0, a.b.1): collapse them back to the list key.
                if (preg_match('/^(.*)\.\d+$/', $path, $m) === 1) {
                    $key = $file.'.'.$m[1];
                    $value = $this->defaultOf($key);
                    $path = $m[1];
                }
                $type = $this->typeOf($value);
                if ($type === null || ! self::isAllowed($key) || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $section = str_contains($path, '.') ? substr($path, 0, (int) strrpos($path, '.')) : 'genel';
                $sections[$section][] = [
                    'key' => $key, 'name' => substr($path, strlen($section) + ($section === 'genel' ? 0 : 1)), 'type' => $type,
                    'value' => config($key), 'default' => $this->defaultOf($key), 'overridden' => array_key_exists($key, self::$defaults),
                ];
            }
            $out[$file] = ['label' => $label, 'sections' => $sections];
        }

        return $out;
    }

    public function defaultOf(string $key): mixed
    {
        return array_key_exists($key, self::$defaults) ? self::$defaults[$key] : config($key);
    }

    /** @return array<string, mixed> */
    private function defaultsOf(string $file): array
    {
        $config = (array) config($file);
        foreach (self::$defaults as $key => $value) {
            if (str_starts_with($key, $file.'.')) {
                Arr::set($config, substr($key, strlen($file) + 1), $value);
            }
        }

        return $config;
    }

    public function set(string $key, mixed $value, ?int $userId): void
    {
        if (! self::isAllowed($key) && ! str_ends_with($key, '.disabled_rules')) {
            throw new InvalidArgumentException('Not an editable method setting: '.$key);
        }
        $default = $this->defaultOf($key);
        $value = $this->coerce($value, $default, $key);
        self::$defaults[$key] ??= $default;
        DB::table('method_settings')->updateOrInsert(['config_key' => $key], ['value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_by' => $userId, 'updated_at' => now(), 'created_at' => now()]);
        config([$key => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    public function reset(string $key): void
    {
        DB::table('method_settings')->where('config_key', $key)->delete();
        if (array_key_exists($key, self::$defaults)) {
            config([$key => self::$defaults[$key]]);
            unset(self::$defaults[$key]);
        }
        Cache::forget(self::CACHE_KEY);
    }

    /** @return list<string> */
    public function disabledRules(string $scope): array
    {
        return array_values(array_map('strval', (array) config(($scope === 'seo' ? 'moxdop-seo-tasks' : 'moxdop-advisor').'.disabled_rules', [])));
    }

    public function setRuleEnabled(string $scope, string $ruleId, bool $enabled, ?int $userId): void
    {
        $key = ($scope === 'seo' ? 'moxdop-seo-tasks' : 'moxdop-advisor').'.disabled_rules';
        $disabled = array_values(array_diff($this->disabledRules($scope), [$ruleId]));
        if (! $enabled) {
            $disabled[] = $ruleId;
        }
        self::$defaults[$key] ??= [];
        DB::table('method_settings')->updateOrInsert(['config_key' => $key], ['value' => json_encode(array_values(array_unique($disabled))), 'updated_by' => $userId, 'updated_at' => now(), 'created_at' => now()]);
        config([$key => array_values(array_unique($disabled))]);
        Cache::forget(self::CACHE_KEY);
    }

    public static function isAllowed(string $key): bool
    {
        foreach (self::FILES as $file => [, $excluded]) {
            if (! str_starts_with($key, $file.'.')) {
                continue;
            }
            $path = substr($key, strlen($file) + 1);
            if (in_array($path, ['disabled_rules'], true)) {
                return true;
            }
            foreach ($excluded as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix.'.')) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function typeOf(mixed $value): ?string
    {
        return match (true) {
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_array($value) && array_is_list($value) && $value !== [] && array_filter($value, 'is_string') === $value => 'list',
            default => null,
        };
    }

    private function coerce(mixed $value, mixed $default, string $key): mixed
    {
        if (is_array($default) || str_ends_with($key, '.disabled_rules')) {
            $items = is_array($value) ? $value : (preg_split('/[\r\n,]+/', (string) $value) ?: []);

            return array_values(array_unique(array_filter(array_map(fn ($v): string => trim((string) $v), $items), fn (string $v): bool => $v !== '')));
        }
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Sayı bekleniyor: '.$key);
        }

        return is_int($default) && (string) (int) $value === trim((string) $value) ? (int) $value : (float) $value;
    }
}

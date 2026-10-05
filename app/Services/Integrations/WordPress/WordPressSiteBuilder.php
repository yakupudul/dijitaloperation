<?php

namespace App\Services\Integrations\WordPress;

use App\Jobs\ExecuteExternalWriteJob;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Connector 1.8.0 site building for Claude (MCP). A site is built only while both switches are on: "Site building" in
 * the plugin settings on the site, and "Claude site kurulumu" on the site in MoxDOP. The MoxDOP switch has two modes
 * an Admin picks: "doğrudan" (Claude's build is applied at once; the Admin who switched it on is the approver) and
 * "onaylı" (the build waits in the site's log until an Admin approves or rejects it). Every build is one
 * ExternalWriteAction row (`site_build`) shown in the site's log; an applied build is undone from there (the plugin
 * restores what it changed and removes what it made, newest first).
 */
final class WordPressSiteBuilder
{
    public const string MODE_DIRECT = 'direct';

    public const string MODE_APPROVAL = 'approval';

    public const string AWAITING = 'awaiting_approval';

    public const string REJECTED = 'rejected';

    private const string FILE_DISK = 'local';

    public function __construct(private readonly WordPressConnectorClient $client) {}

    /**
     * Paired WordPress sites with their build state.
     *
     * @return list<array{site_id: int, name: string, brand: ?string, url: ?string, plugin_version: ?string, mode: ?string, plugin_allows_build: bool, ready: bool, reason: ?string}>
     */
    public function sites(): array
    {
        return $this->connections()->map(function (CoreConnection $connection): array {
            $state = $this->state($connection);

            return [
                'site_id' => (int) $connection->digital_asset_id,
                'name' => (string) $connection->digitalAsset?->name,
                'brand' => $connection->digitalAsset?->brand?->name,
                'url' => $connection->digitalAsset?->primary_url ?: $connection->digitalAsset?->domain,
                'plugin_version' => data_get($connection->config, 'plugin_version'),
                'mode' => self::mode($connection),
                'plugin_allows_build' => in_array('build', (array) data_get($connection->config, 'capabilities', []), true),
                'ready' => $state === null,
                'reason' => $state,
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    public function inspect(int $siteId): array
    {
        return $this->client->buildInspect($this->connection($siteId));
    }

    /**
     * Claude's build: applied now in direct mode, or left waiting for an Admin in approval mode.
     *
     * @param  list<array<string, mixed>>  $operations
     * @return array{action_id: int, status: string, results: list<array<string, mixed>>}
     */
    public function build(int $siteId, array $operations): array
    {
        $connection = $this->connection($siteId);
        $awaiting = self::mode($connection) === self::MODE_APPROVAL;
        $action = ExternalWriteAction::query()->create([
            'channel' => ExternalWriteAction::CHANNEL_WORDPRESS, 'action' => ExternalWriteAction::ACTION_SITE_BUILD,
            'digital_asset_id' => $siteId, 'brand_id' => $connection->digitalAsset?->brand_id,
            'status' => $awaiting ? self::AWAITING : 'running', 'started_at' => $awaiting ? null : now(),
            'request_payload' => ['label' => 'Claude site kurulumu', 'mode' => self::mode($connection), 'operations' => array_map(self::storeFileData(...), $operations)],
            'requested_by' => (int) data_get($connection->config, 'claude_build.by'),
        ]);
        if ($awaiting) {
            return ['action_id' => $action->id, 'status' => self::AWAITING, 'results' => []];
        }
        try {
            $result = $this->apply($action);
        } catch (Throwable $exception) {
            $action->forceFill(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($exception->getMessage(), 0, 500)])->save();

            throw $exception;
        }
        $action->forceFill(['status' => $result['status'], 'result' => $result, 'finished_at' => now(), 'error' => null])->save();

        return ['action_id' => $action->id, 'status' => $result['status'], 'results' => $result['results']];
    }

    /**
     * Sends a build to the site (direct mode at once, approval mode from the queue after approval).
     *
     * @return array{results: list<array<string, mixed>>, status: string, approved_by?: int, approved_at?: string}
     */
    public function apply(ExternalWriteAction $action): array
    {
        $connection = $this->connection((int) $action->digital_asset_id);
        $operations = array_map(self::loadFileData(...), (array) data_get($action->request_payload, 'operations', []));
        try {
            $results = array_values((array) ($this->client->build($connection, $operations)['results'] ?? []));
        } finally {
            self::deleteFiles($action);
        }
        $ok = count(array_filter($results, fn (mixed $result): bool => (bool) data_get($result, 'ok')));

        return ['results' => $results, 'status' => $ok === count($results) ? 'succeeded' : ($ok > 0 ? 'partial' : 'failed')]
            + array_intersect_key((array) $action->result, ['approved_by' => true, 'approved_at' => true]);
    }

    /**
     * Undo of an applied build, newest operation first. Something changed on the site after the build stops the undo
     * unless the Admin forced it ("Yine de geri al").
     *
     * @return array<string, mixed>
     */
    public function undo(ExternalWriteAction $action): array
    {
        $connection = $this->siteConnection((int) $action->digital_asset_id);
        $changeIds = array_reverse(self::changeIds($action));
        if ($changeIds === []) {
            return ['restored' => 0];
        }
        $results = (array) ($this->client->buildUndo($connection, $changeIds, (bool) data_get($action->result, 'force_undo', false))['results'] ?? []);
        $conflicts = array_values(array_filter($results, fn (array $r): bool => ($r['error'] ?? null) === 'changed_since'));
        $failed = array_values(array_filter($results, fn (array $r): bool => ! ($r['ok'] ?? false) && ! in_array($r['error'] ?? null, ['changed_since', 'unknown or already undone'], true)));
        if ($conflicts !== []) {
            throw new RuntimeException(count($conflicts).' öğe kurulumdan sonra sitede değiştirilmiş; üzerine yazılmadı. "Yine de geri al" ile zorla.');
        }
        if ($failed !== []) {
            throw new RuntimeException('Geri alınamayan adım var: '.mb_substr(implode('; ', array_map(fn (array $r): string => implode(', ', (array) ($r['errors'] ?? [$r['error'] ?? '?'])), $failed)), 0, 300));
        }

        return ['restored' => count($results), 'results' => $results];
    }

    /** An Admin approves a waiting build; it runs on the queue like every approved write. */
    public function approve(User $user, ExternalWriteAction $action): void
    {
        $this->guard($user, $action);
        if (ExternalWriteAction::query()->whereKey($action->id)->where('status', self::AWAITING)->update(['status' => 'queued', 'updated_at' => now()]) !== 1) {
            throw ValidationException::withMessages(['write' => 'Bu kurulum artık onay beklemiyor.']);
        }
        $action->forceFill(['result' => array_merge((array) $action->result, ['approved_by' => $user->id, 'approved_at' => now()->toIso8601String()])])->save();
        dispatch(new ExecuteExternalWriteJob($action->id))
            ->onConnection((string) config('moxdop-external-writes.queue_connection', config('queue.default')))
            ->onQueue((string) config('moxdop-external-writes.queue', 'default'));
    }

    public function reject(User $user, ExternalWriteAction $action, ?string $reason = null): void
    {
        $this->guard($user, $action);
        if (ExternalWriteAction::query()->whereKey($action->id)->where('status', self::AWAITING)->update(['status' => self::REJECTED, 'finished_at' => now(), 'updated_at' => now()]) !== 1) {
            throw ValidationException::withMessages(['write' => 'Bu kurulum artık onay beklemiyor.']);
        }
        $action->refresh()->forceFill(['result' => ['rejected_by' => $user->id, 'reason' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null]])->save();
        self::deleteFiles($action);
    }

    /** "Yine de geri al": the next undo overwrites values changed on the site since the build. */
    public function forceUndo(User $user, ExternalWriteAction $action): void
    {
        $this->guard($user, $action);
        $action->forceFill(['result' => array_merge((array) $action->result, ['force_undo' => true])])->save();
    }

    /**
     * The site's build log, newest first, for the panel and for Claude.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $siteId, int $limit = 20): array
    {
        return ExternalWriteAction::query()->where('digital_asset_id', $siteId)->where('action', ExternalWriteAction::ACTION_SITE_BUILD)
            ->latest('id')->limit($limit)->get()
            ->map(fn (ExternalWriteAction $action): array => [
                'id' => $action->id,
                'status' => $action->status,
                'status_label' => $action->statusLabel(),
                'mode' => data_get($action->request_payload, 'mode'),
                'created_at' => $action->created_at?->toIso8601String(),
                'finished_at' => $action->finished_at?->toIso8601String(),
                'undone_at' => $action->undone_at?->toIso8601String(),
                'error' => $action->error,
                'reject_reason' => data_get($action->result, 'reason'),
                'undoable' => $action->isUndoable(),
                'operations' => self::summary($action),
            ])->all();
    }

    /**
     * One line per operation: what it is, its target, and what happened.
     *
     * @return list<array{op: string, label: string, target: string, detail: ?string, ok: ?bool, error: ?string, url: ?string, edit_url: ?string}>
     */
    public static function summary(ExternalWriteAction $action): array
    {
        $results = array_values((array) data_get($action->result, 'results', []));
        $operations = array_values((array) data_get($action->request_payload, 'operations', []));

        return array_values(array_map(function (array $operation, int $i) use ($results): array {
            $result = (array) ($results[$i] ?? []);
            $op = (string) ($operation['op'] ?? '');
            $errors = array_filter([(string) ($result['error'] ?? ''), ...array_map('strval', (array) ($result['errors'] ?? [])), ...array_map('strval', (array) ($result['warnings'] ?? []))]);

            return [
                'op' => $op,
                'label' => match ($op) {
                    'acf_import' => 'ACF içe aktarma',
                    'post' => match ($result['created'] ?? null) {
                        true => 'Yeni '.self::typeName($operation),
                        false => ucfirst(self::typeName($operation)).' güncelleme',
                        default => ucfirst(self::typeName($operation)),
                    },
                    'elementor_template' => 'Elementor şablonu ('.($operation['type'] ?? data_get($operation, 'template.type', '?')).')',
                    'media' => 'Görsel',
                    'menu' => 'Menü',
                    'settings' => 'Site ayarları',
                    'trash' => 'Çöpe taşı',
                    default => $op,
                },
                'target' => (string) match ($op) {
                    'acf_import' => implode(', ', array_map(fn (mixed $item): string => (string) (data_get($item, 'title') ?: data_get($item, 'key')), array_slice(isset($operation['items']['key']) ? [$operation['items']] : (array) ($operation['items'] ?? []), 0, 6))),
                    'menu' => ($operation['name'] ?? '').(isset($operation['location']) ? ' → '.$operation['location'] : ''),
                    'settings' => implode(', ', array_keys((array) ($operation['values'] ?? []))),
                    'elementor_template' => ($operation['title'] ?? data_get($operation, 'template.title') ?? '').' · '.($operation['ref'] ?? ''),
                    default => trim(($operation['title'] ?? $operation['filename'] ?? '').' · '.($operation['ref'] ?? ''), ' ·'),
                },
                'detail' => match ($op) {
                    'post' => implode(' · ', array_filter([
                        isset($operation['status']) ? 'durum: '.$operation['status'] : null,
                        isset($operation['slug']) ? '/'.$operation['slug'] : null,
                        isset($operation['acf']) ? count((array) $operation['acf']).' ACF alanı' : null,
                        isset($operation['elementor']) ? 'Elementor içeriği' : null,
                        isset($operation['seo']) ? 'SEO' : null,
                    ])) ?: null,
                    'elementor_template' => isset($operation['conditions']) ? 'koşul: '.implode(', ', (array) $operation['conditions']) : null,
                    'menu' => count((array) ($operation['items'] ?? [])).' öğe',
                    default => null,
                },
                'ok' => $result === [] ? null : (bool) ($result['ok'] ?? false),
                'error' => $errors === [] ? null : mb_substr(implode('; ', $errors), 0, 300),
                'url' => is_string($result['url'] ?? null) && $result['url'] !== '' ? $result['url'] : null,
                'edit_url' => is_string($result['edit_url'] ?? null) && $result['edit_url'] !== '' ? $result['edit_url'] : null,
            ];
        }, $operations, array_keys($operations)));
    }

    /** @param  array<string, mixed>  $operation */
    private static function typeName(array $operation): string
    {
        $type = (string) ($operation['post_type'] ?? 'page');

        return match ($type) {
            'page' => 'sayfa',
            'post' => 'yazı',
            default => $type.' kaydı',
        };
    }

    /** @return list<string> */
    public static function changeIds(ExternalWriteAction $action): array
    {
        return array_values(array_filter(array_map(fn (mixed $r): string => (string) data_get($r, 'change_id', ''), (array) data_get($action->result, 'results', []))));
    }

    /** An Admin sets the site's mode: null (off), direct or approval. */
    public function setMode(DigitalAsset $site, User $user, ?string $mode): void
    {
        abort_unless($user->is_active && $user->hasRole(Roles::ADMIN), 403, 'Site kurulumunu yalnız Admin açıp kapatabilir.');
        $connection = $this->connections()->firstWhere('digital_asset_id', $site->id);
        if ($connection === null) {
            throw new RuntimeException('Bu sitede eşleştirilmiş WordPress Connector yok.');
        }
        $config = (array) $connection->config;
        $config['claude_build'] = in_array($mode, [self::MODE_DIRECT, self::MODE_APPROVAL], true)
            ? ['enabled' => true, 'mode' => $mode, 'by' => $user->id, 'at' => now()->toIso8601String()]
            : ['enabled' => false];
        $connection->forceFill(['config' => $config])->save();
    }

    public static function switchedOn(?CoreConnection $connection): bool
    {
        return $connection !== null && (bool) data_get($connection->config, 'claude_build.enabled', false) && (int) data_get($connection->config, 'claude_build.by') > 0;
    }

    /** direct | approval while on, null while off. */
    public static function mode(?CoreConnection $connection): ?string
    {
        return self::switchedOn($connection) ? (data_get($connection->config, 'claude_build.mode') === self::MODE_APPROVAL ? self::MODE_APPROVAL : self::MODE_DIRECT) : null;
    }

    private function guard(User $user, ExternalWriteAction $action): void
    {
        abort_unless($user->is_active && $user->hasRole(Roles::ADMIN), 403, 'Site kurulumunu yalnız Admin onaylar.');
        abort_unless($action->action === ExternalWriteAction::ACTION_SITE_BUILD, 404);
    }

    private function connection(int $siteId): CoreConnection
    {
        $connection = $this->connections()->firstWhere('digital_asset_id', $siteId);
        if ($connection === null) {
            throw new RuntimeException('Bu sitede eşleştirilmiş WordPress Connector yok.');
        }
        $reason = $this->state($connection);
        if ($reason !== null && self::switchedOn($connection) && ! $this->pluginReady($connection)) {
            // The stored version / capabilities may be older than the site: ask the site once before refusing.
            try {
                $this->client->status($connection);
            } catch (Throwable) {
            }
            $connection->refresh();
            $reason = $this->state($connection);
        }
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }

        return $connection;
    }

    /** Undo works while the plugin allows building, even after the MoxDOP switch was turned off. */
    private function siteConnection(int $siteId): CoreConnection
    {
        $connection = $this->connections()->firstWhere('digital_asset_id', $siteId);
        if ($connection === null) {
            throw new RuntimeException('Bu sitede eşleştirilmiş WordPress Connector yok.');
        }

        return $connection;
    }

    /** Why this site cannot be built now, or null when it can. */
    private function state(CoreConnection $connection): ?string
    {
        if (! (bool) config('moxdop-external-writes.enabled', true) || ! (bool) config('moxdop-external-writes.wordpress.enabled', true)) {
            return 'Harici yazma kapalı (EXTERNAL_WRITES_ENABLED).';
        }
        if (! self::switchedOn($connection)) {
            return 'Bu sitede "Claude site kurulumu" kapalı. MoxDOP › Entegrasyonlar › WordPress bağlayıcısı ekranından aç.';
        }
        $version = (string) data_get($connection->config, 'plugin_version', '0.0.0');
        $minimum = (string) config('moxdop-wordpress.build_min_plugin_version', '1.8.0');
        if (version_compare($version, $minimum, '<')) {
            return 'Sitedeki eklenti '.$version.'; site kurulumu için en az '.$minimum.' gerekli. Eklentiyi güncelle.';
        }
        if (! in_array('build', (array) data_get($connection->config, 'capabilities', []), true)) {
            return 'Sitedeki eklentide "Site building" kapalı. WordPress › Ayarlar › MoxDOP Connector ekranından aç.';
        }

        return null;
    }

    private function pluginReady(CoreConnection $connection): bool
    {
        return version_compare((string) data_get($connection->config, 'plugin_version', '0.0.0'), (string) config('moxdop-wordpress.build_min_plugin_version', '1.8.0'), '>=')
            && in_array('build', (array) data_get($connection->config, 'capabilities', []), true);
    }

    /** @return Collection<int, CoreConnection> */
    private function connections(): Collection
    {
        return CoreConnection::query()->with(['credential', 'digitalAsset.brand'])
            ->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
            ->where('enabled', true)
            ->where('config->pairing_state', 'paired')
            ->orderBy('id')
            ->get();
    }

    /**
     * Base64 file bytes are kept on disk until the build is sent (or rejected), never in the database.
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private static function storeFileData(array $operation): array
    {
        if (isset($operation['data_base64']) && is_string($operation['data_base64'])) {
            $path = 'site-build/'.Str::uuid().'.b64';
            Storage::disk(self::FILE_DISK)->put($path, $operation['data_base64']);
            $operation['data_bytes'] = (int) floor(strlen($operation['data_base64']) * 3 / 4);
            $operation['data_file'] = $path;
            unset($operation['data_base64']);
        }

        return $operation;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private static function loadFileData(array $operation): array
    {
        $path = $operation['data_file'] ?? null;
        unset($operation['data_file'], $operation['data_bytes']);
        if (is_string($path) && str_starts_with($path, 'site-build/')) {
            $data = Storage::disk(self::FILE_DISK)->get($path);
            if ($data === null) {
                throw new RuntimeException('Görsel dosyası bulunamadı ('.($operation['filename'] ?? $path).'); Claude yeniden göndermeli.');
            }
            $operation['data_base64'] = $data;
        }

        return $operation;
    }

    private static function deleteFiles(ExternalWriteAction $action): void
    {
        foreach ((array) data_get($action->request_payload, 'operations', []) as $operation) {
            $path = $operation['data_file'] ?? null;
            if (is_string($path) && str_starts_with($path, 'site-build/')) {
                Storage::disk(self::FILE_DISK)->delete($path);
            }
        }
    }
}

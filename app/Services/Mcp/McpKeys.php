<?php

namespace App\Services\Mcp;

use App\Mcp\Servers\MoxdopServer;
use App\Models\AgencySetting;
use App\Models\McpToken;
use App\Services\AiTasks\AiWorkers;
use App\Services\AiTasks\McpCaller;
use App\Support\SchemaState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use ReflectionClass;

/**
 * Who may use the MoxDOP MCP server. A key made in the panel is shown once and stored only as a hash; a read key may
 * call only the tools that change nothing (IsReadOnly plus the readers below), a write key everything. The old
 * MOXDOP_MCP_TOKEN key still works as a write key until an Admin turns it off in the panel (no server change needed).
 */
final class McpKeys
{
    public const string PREFIX = 'mox_';

    /** Readers without the IsReadOnly annotation (they only remember what Claude last read). */
    private const array EXTRA_READ_TOOLS = ['get-brand', 'get-page', 'task-result'];

    /** A new key: returns the plain text, which is never stored or shown again. */
    public static function issue(string $name, string $scope, ?int $userId, string $provider = AiWorkers::CLAUDE_MCP): string
    {
        $plain = self::PREFIX.Str::random(48);
        McpToken::query()->create([
            'name' => Str::limit(trim($name) !== '' ? trim($name) : 'MCP anahtarı', 80, ''),
            'scope' => $scope === McpToken::WRITE ? McpToken::WRITE : McpToken::READ,
            ...(SchemaState::hasColumn('mcp_tokens', 'provider') ? ['provider' => AiWorkers::isMcp($provider) ? $provider : AiWorkers::CLAUDE_MCP] : []),
            'token_hash' => hash('sha256', $plain),
            'last_four' => substr($plain, -4),
            'created_by' => $userId,
        ]);

        return $plain;
    }

    public static function revoke(int $id): void
    {
        McpToken::query()->whereKey($id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public static function envConfigured(): bool
    {
        return trim((string) config('moxdop-mcp.token')) !== '';
    }

    public static function envKeyOn(): bool
    {
        return self::envConfigured() && ! self::envKeyTurnedOff();
    }

    public static function setEnvKeyOff(bool $off): void
    {
        AgencySetting::query()->first()?->forceFill(['mcp_env_token_off' => $off])->save();
        self::$envOff = null;
    }

    /** The server answers at all: the old key is on or at least one panel key is not revoked. */
    public static function serverOpen(): bool
    {
        if (self::envKeyOn()) {
            return true;
        }
        try {
            return McpToken::query()->whereNull('revoked_at')->exists();
        } catch (QueryException) {
            return false;
        }
    }

    /** The scope of a given bearer key (read / write), or null when it is not a valid key. */
    public static function scopeOf(string $given): ?string
    {
        return self::callerOf($given)?->scope;
    }

    /** Who a given bearer key is (worker, key id, scope), or null when it is not a valid key. */
    public static function callerOf(string $given): ?McpCaller
    {
        if ($given === '') {
            return null;
        }
        if (self::envKeyOn() && hash_equals(trim((string) config('moxdop-mcp.token')), $given)) {
            return new McpCaller(AiWorkers::CLAUDE_MCP, null, McpToken::WRITE);
        }
        if (! str_starts_with($given, self::PREFIX)) {
            return null;
        }
        try {
            $token = McpToken::query()->where('token_hash', hash('sha256', $given))->whereNull('revoked_at')->first();
        } catch (QueryException) {
            return null;
        }
        if ($token === null) {
            return null;
        }
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return new McpCaller(AiWorkers::isMcp((string) ($token->provider ?? '')) ? (string) $token->provider : AiWorkers::CLAUDE_MCP, (int) $token->id, (string) $token->scope);
    }

    /** @return list<string> tool names a read key may call */
    public static function readTools(): array
    {
        return once(function (): array {
            $tools = (new ReflectionClass(MoxdopServer::class))->getDefaultProperties()['tools'] ?? [];
            $names = self::EXTRA_READ_TOOLS;
            foreach ($tools as $class) {
                $reflection = new ReflectionClass($class);
                if ($reflection->getAttributes(IsReadOnly::class) !== []) {
                    $names[] = Str::kebab(class_basename($class));
                }
            }

            return array_values(array_unique($names));
        });
    }

    /** @var array{0: bool, 1: float}|null the setting and when it was read (pool #54: read once per row before) */
    private static ?array $envOff = null;

    private static function envKeyTurnedOff(): bool
    {
        if (self::$envOff !== null && microtime(true) - self::$envOff[1] < 30 && ! app()->runningUnitTests()) {
            return self::$envOff[0];
        }
        try {
            $off = (bool) AgencySetting::query()->value('mcp_env_token_off');
        } catch (QueryException) {
            return false;
        }
        self::$envOff = [$off, microtime(true)];

        return $off;
    }
}

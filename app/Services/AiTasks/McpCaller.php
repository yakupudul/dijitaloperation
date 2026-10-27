<?php

namespace App\Services\AiTasks;

/**
 * Who is calling the MoxDOP MCP server in this request: the worker the key belongs to (Claude MCP / ChatGPT MCP), the
 * key's id (null for the old server key) and its scope. AuthenticateMcpToken sets it; without a key (a test calling a
 * tool directly, a console command) it is Claude MCP with a write scope, as before.
 */
final class McpCaller
{
    public function __construct(
        public readonly string $worker = AiWorkers::CLAUDE_MCP,
        public readonly ?int $tokenId = null,
        public readonly string $scope = 'write',
    ) {}

    public static function current(): self
    {
        return app()->bound(self::class) ? app(self::class) : new self;
    }

    public function writes(): bool
    {
        return $this->scope === 'write';
    }
}

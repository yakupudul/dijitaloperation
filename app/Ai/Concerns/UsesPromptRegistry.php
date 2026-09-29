<?php

namespace App\Ai\Concerns;

use App\Models\PromptVersion;
use App\Services\Prompts\PromptRegistry;
use Stringable;

/**
 * Instructions rendered from the operation's current prompt version. The version is pinned on first use so the
 * instructions and the usage record of one agent instance always refer to the same version.
 */
trait UsesPromptRegistry
{
    private ?PromptVersion $pinnedPromptVersion = null;

    public function instructions(): Stringable|string
    {
        return app(PromptRegistry::class)->renderVersion($this->promptVersion(), $this->promptVariables());
    }

    public function promptVersion(): PromptVersion
    {
        return $this->pinnedPromptVersion ??= app(PromptRegistry::class)->current($this->promptOperation());
    }

    public function promptVersionId(): ?int
    {
        return (int) $this->promptVersion()->id;
    }

    /** @return array<string, mixed> */
    protected function promptVariables(): array
    {
        return [];
    }
}

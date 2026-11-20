<?php

namespace App\Panel\Support;

use App\Panel\Models\PanelMembership;
use Closure;

/**
 * Which brand the current request may see. Every brand-owned panel model reads this: with a brand it is filtered to
 * that brand, inside system() it is unfiltered (form intake, counts for the operator), otherwise it returns nothing.
 */
final class PanelContext
{
    private ?int $brandId = null;

    private ?PanelMembership $membership = null;

    private int $systemDepth = 0;

    public function enter(PanelMembership $membership): void
    {
        $this->membership = $membership;
        $this->brandId = (int) $membership->brand_id;
    }

    public function leave(): void
    {
        $this->membership = null;
        $this->brandId = null;
    }

    public function brandId(): ?int
    {
        return $this->brandId;
    }

    public function membership(): ?PanelMembership
    {
        return $this->membership;
    }

    public function isSystem(): bool
    {
        return $this->systemDepth > 0;
    }

    /**
     * Runs code that must work across brands or without a signed-in brand user. The caller names the brand itself.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function system(Closure $callback): mixed
    {
        $this->systemDepth++;
        try {
            return $callback();
        } finally {
            $this->systemDepth--;
        }
    }

    public function can(string $permission): bool
    {
        return $this->membership !== null && $this->membership->allows($permission);
    }

    public function isManager(): bool
    {
        return (bool) $this->membership?->is_manager;
    }
}

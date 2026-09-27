<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Services\Ownership\OwnershipConflict;
use App\Support\Roles;

/**
 * Livewire state for the inline yetki devri panel (x-operator.ownership-transfer-panel): the conflict found by the
 * OwnershipGuard, what the "Devret" button should redo (`pendingTransfer`), and the operator's explicit acknowledgement.
 */
trait ConfirmsOwnershipTransfer
{
    /** @var array<string, mixed>|null */
    public ?array $ownershipConflict = null;

    /** @var array<string, mixed> */
    public array $pendingTransfer = [];

    public bool $transferAcknowledged = false;

    public string $transferNote = '';

    /** @param  array<string, mixed>  $pending */
    protected function presentOwnershipConflict(OwnershipConflict $conflict, array $pending = []): void
    {
        $this->ownershipConflict = $conflict->toArray();
        $this->pendingTransfer = $pending;
        $this->transferAcknowledged = false;
        $this->transferNote = '';
    }

    public function cancelOwnershipTransfer(): void
    {
        $this->ownershipConflict = null;
        $this->pendingTransfer = [];
        $this->transferAcknowledged = false;
        $this->transferNote = '';
    }

    protected function canTransferOwnership(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole(Roles::ADMIN);
    }

    /** Admin + ticked "Yetki devrini onaylıyorum"; returns the acting user or null (error added). */
    protected function ownershipTransferActor(): ?User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->hasRole(Roles::ADMIN), 403);
        if ($this->ownershipConflict === null) {
            return null;
        }
        if (! $this->transferAcknowledged) {
            $this->addError('transferAcknowledged', 'Devretmek için "Yetki devrini onaylıyorum" kutusunu işaretleyin.');

            return null;
        }
        $this->resetErrorBag('transferAcknowledged');

        return $user;
    }
}

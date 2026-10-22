<?php

namespace App\Livewire\Operator\Settings;

use App\Models\McpToken;
use App\Services\Mcp\McpKeys;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Ayarlar › MCP anahtarları (Admin): the keys Claude connects to MoxDOP with. A new key is shown once here and goes
 * straight into the claude.ai connector setting (never into a chat); read keys only read, write keys also do queue
 * work. The old server key can be turned off here once a panel key is in use.
 */
#[Layout('operator.layouts.app')]
#[Title('MCP anahtarları')]
final class McpKeysPage extends Component
{
    public string $name = '';

    public string $scope = McpToken::READ;

    /** The key just made: shown once, gone on the next page load. */
    public ?string $newKey = null;

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function create(): void
    {
        $this->authorizeAdmin();
        $this->validate(['name' => ['required', 'string', 'max:80'], 'scope' => ['required', 'in:read,write']]);
        $this->newKey = McpKeys::issue($this->name, $this->scope, auth()->id());
        $this->reset('name');
    }

    public function revoke(int $id): void
    {
        $this->authorizeAdmin();
        McpKeys::revoke($id);
    }

    public function setEnvKey(bool $on): void
    {
        $this->authorizeAdmin();
        McpKeys::setEnvKeyOff(! $on);
    }

    public function forgetNewKey(): void
    {
        $this->newKey = null;
    }

    public function render(): View
    {
        return view('livewire.operator.settings.mcp-keys', [
            'keys' => McpToken::query()->orderByRaw('revoked_at is not null')->latest('id')->get(),
            'envConfigured' => McpKeys::envConfigured(),
            'envOn' => McpKeys::envKeyOn(),
            'serverUrl' => url((string) config('moxdop-mcp.path', 'mcp/moxdop')),
        ]);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}

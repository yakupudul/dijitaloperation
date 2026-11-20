<?php

namespace App\Panel\Support;

use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelUser;
use Illuminate\Support\Facades\Auth;

/**
 * Every panel screen: on each request (the first load and every later click) the user must still belong to an open
 * brand and still hold the screen's permission.
 */
trait PanelPage
{
    /** The permission this screen needs; "manager" for the brand's manager only. */
    abstract protected function permission(): string;

    public function bootPanelPage(): void
    {
        $context = app(PanelContext::class);
        abort_if($context->membership() === null, 403);
        $permission = $this->permission();
        abort_unless($permission === 'manager' ? $context->isManager() : $context->can($permission), 403);
    }

    protected function panel(): PanelContext
    {
        return app(PanelContext::class);
    }

    protected function membership(): PanelMembership
    {
        return app(PanelContext::class)->membership();
    }

    protected function panelUser(): PanelUser
    {
        return Auth::guard('client')->user();
    }

    protected function may(string $permission): bool
    {
        return app(PanelContext::class)->can($permission);
    }

    protected function notify(string $message, string $tone = 'ok'): void
    {
        $this->dispatch('operator-notice', message: $message, tone: $tone);
    }
}

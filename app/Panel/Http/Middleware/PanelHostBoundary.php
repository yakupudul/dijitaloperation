<?php

namespace App\Panel\Http\Middleware;

use App\Panel\Models\PanelBrandSetting;
use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelUser;
use App\Panel\Support\PanelContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The wall between the two hosts of one install. On the panel host only the client screens answer, sign-in runs on
 * the client guard, and the signed-in user's brand becomes the only brand the request can read. On every other host
 * the panel context stays empty, so panel data cannot be read there.
 */
final class PanelHostBoundary
{
    public function __construct(private readonly PanelContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->leave();
        if (! self::isPanelHost($request)) {
            return $next($request);
        }

        Auth::shouldUse('client');
        $name = (string) $request->route()?->getName();
        $livewire = str_contains($name, 'livewire') || str_starts_with($request->path(), 'livewire');
        abort_unless($livewire || str_starts_with($name, 'panel.'), 404);
        if ($livewire) {
            $this->refuseForeignComponents($request);
        }

        $user = Auth::guard('client')->user();
        if ($user instanceof PanelUser) {
            if (! $user->is_active) {
                Auth::guard('client')->logout();
            } else {
                $membership = self::membershipFor($user, $request);
                if ($membership !== null) {
                    $this->context->enter($membership);
                }
            }
        }

        return $next($request);
    }

    public static function isPanelHost(Request $request): bool
    {
        return strcasecmp($request->getHost(), (string) config('panel.domain')) === 0;
    }

    /** The brand picked in the session, else the user's first open brand. A switched-off or closed brand is skipped. */
    public static function membershipFor(PanelUser $user, Request $request): ?PanelMembership
    {
        $open = PanelBrandSetting::query()->where('is_enabled', true)->pluck('brand_id')->all();
        $memberships = PanelMembership::query()->with(['role', 'brand:id,name,logo_url'])
            ->where('panel_user_id', $user->id)->where('is_active', true)->whereIn('brand_id', $open)->orderBy('id')->get();
        $picked = $request->hasSession() ? (int) $request->session()->get('panel.brand_id', 0) : 0;

        return $memberships->firstWhere('brand_id', $picked) ?? $memberships->first();
    }

    /** A Livewire update on the panel host may only carry panel components. */
    private function refuseForeignComponents(Request $request): void
    {
        foreach ((array) $request->input('components', []) as $component) {
            $snapshot = json_decode((string) (is_array($component) ? ($component['snapshot'] ?? '') : ''), true);
            $name = (string) data_get($snapshot, 'memo.name', '');
            abort_if($name !== '' && ! str_starts_with($name, 'panel.'), 404);
        }
    }
}

<?php

namespace App\Panel\Services;

use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelNotification;
use App\Panel\Support\PanelContext;

/** Writes the lines under the bell. Works without a signed-in brand user (site form, the agency's reply). */
final class PanelNotifier
{
    public function __construct(private readonly PanelContext $context) {}

    /**
     * Tells every active user of the brand who holds the permission (null: everyone).
     *
     * @param  list<int>  $except  panel user ids that already know (the one who did it)
     */
    public function brand(int $brandId, string $type, string $title, ?string $url, ?string $permission = null, array $except = []): int
    {
        return $this->context->system(function () use ($brandId, $type, $title, $url, $permission, $except): int {
            $sent = 0;
            $members = PanelMembership::query()->with('role')->where('brand_id', $brandId)->where('is_active', true)->get();
            foreach ($members as $member) {
                if (in_array((int) $member->panel_user_id, $except, true) || ($permission !== null && ! $member->allows($permission))) {
                    continue;
                }
                $this->write($brandId, (int) $member->panel_user_id, $type, $title, $url);
                $sent++;
            }

            return $sent;
        });
    }

    public function user(int $brandId, int $panelUserId, string $type, string $title, ?string $url): void
    {
        $this->context->system(fn () => $this->write($brandId, $panelUserId, $type, $title, $url));
    }

    private function write(int $brandId, int $panelUserId, string $type, string $title, ?string $url): void
    {
        $row = new PanelNotification;
        $row->brand_id = $brandId;
        $row->fill(['panel_user_id' => $panelUserId, 'type' => $type, 'title' => mb_substr($title, 0, 190), 'url' => $url])->save();
    }
}

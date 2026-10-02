<?php

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\Notifications\NotificationPreferenceCatalog;
use InvalidArgumentException;

/**
 * Per-user in-app / email preference reads and writes.
 * Email flags are persisted but never trigger delivery from this stack.
 */
final class NotificationPreferenceService
{
    /**
     * Default TRUE when no preference row exists.
     */
    public function isInAppEnabled(User $user, string $preferenceKey): bool
    {
        $row = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('preference_key', $preferenceKey)
            ->first();

        if ($row === null) {
            return true;
        }

        return (bool) $row->in_app_enabled;
    }

    public function setPreference(User $user, string $preferenceKey, bool $inApp, bool $email = false): NotificationPreference
    {
        if (! NotificationPreferenceCatalog::isKnown($preferenceKey)) {
            throw new InvalidArgumentException("Unknown notification preference key: {$preferenceKey}");
        }

        /** @var NotificationPreference $row */
        $row = NotificationPreference::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'preference_key' => $preferenceKey,
            ],
            [
                'in_app_enabled' => $inApp,
                'email_enabled' => $email,
            ],
        );

        return $row;
    }

    /**
     * Merged catalog defaults with persisted overrides for Settings UI.
     *
     * @return list<array{preference_key: string, label: string, in_app_enabled: bool, email_enabled: bool}>
     */
    public function listForUser(User $user): array
    {
        $stored = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('preference_key');

        $rows = [];
        foreach (NotificationPreferenceCatalog::defaults() as $default) {
            $key = $default['preference_key'];
            /** @var NotificationPreference|null $row */
            $row = $stored->get($key);
            $rows[] = [
                'preference_key' => $key,
                'label' => $default['label'],
                'in_app_enabled' => $row !== null ? (bool) $row->in_app_enabled : true,
                'email_enabled' => $row !== null ? (bool) $row->email_enabled : false,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{preference_key: string, label: string, in_app_enabled: bool, email_enabled: bool}>
     */
    public function defaults(): array
    {
        return NotificationPreferenceCatalog::defaults();
    }
}

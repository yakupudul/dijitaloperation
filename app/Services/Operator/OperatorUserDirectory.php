<?php

namespace App\Services\Operator;

use App\Models\User;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Support\Collection;

/**
 * Canonical operator directory for Customer Owner / responsibility assignment.
 *
 * Eligible users are real agency operators with /app access. Fixture people
 * (Ayşe / Mert / Selin / Can) are never synthesized.
 */
final class OperatorUserDirectory
{
    /**
     * @return Collection<int, User>
     */
    public static function eligibleOperators(): Collection
    {
        return User::query()
            ->permission(Permissions::ACCESS_APP)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<int>
     */
    public static function eligibleIds(): array
    {
        return self::eligibleOperators()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * Dropdown options keyed by string user id. Unassigned is an empty selection in the UI.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::eligibleOperators()
            ->mapWithKeys(static fn (User $user): array => [(string) $user->id => $user->name])
            ->all();
    }

    /**
     * Settings / wizard team rows.
     *
     * @return list<array{id: string, name: string, email: string, initials: string, role: string}>
     */
    public static function presentationMembers(): array
    {
        return self::eligibleOperators()
            ->map(static function (User $user): array {
                $parts = preg_split('/\s+/', trim($user->name)) ?: [];
                $initials = collect($parts)
                    ->filter()
                    ->map(static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
                    ->take(2)
                    ->implode('');

                return [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'initials' => $initials !== '' ? $initials : mb_strtoupper(mb_substr($user->name, 0, 1)),
                    'role' => $user->hasRole(Roles::ADMIN) ? Roles::ADMIN : Roles::TEAM_MEMBER,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    public static function sanitizeIds(array $ids): array
    {
        $allowed = self::eligibleIds();

        return array_values(array_unique(array_filter(
            array_map(static fn (int|string $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0 && in_array($id, $allowed, true),
        )));
    }
}

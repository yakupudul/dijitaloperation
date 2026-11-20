<?php

namespace App\Panel\Services;

use App\Panel\Models\AccessRole;
use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelUser;
use App\Panel\Support\PanelPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Adding a brand's users and giving passwords; used by the brand's manager on the panel and by the admin in MoxDOP. */
final class PanelAccounts
{
    /**
     * @return array{0: PanelMembership, 1: ?string} the membership, and the temporary password when a new account was made
     */
    public function add(int $brandId, string $name, string $email, ?int $roleId, bool $isManager): array
    {
        $email = mb_strtolower(trim($email));
        $roleId = $this->panelRole($roleId)?->id;
        if (! $isManager && $roleId === null) {
            throw ValidationException::withMessages(['roleId' => 'Bir rol seçin.']);
        }

        return DB::transaction(function () use ($brandId, $name, $email, $roleId, $isManager): array {
            $user = PanelUser::query()->where('email', $email)->first();
            $password = null;
            if ($user === null) {
                $password = self::temporaryPassword();
                $user = PanelUser::query()->create(['name' => trim($name), 'email' => $email, 'password' => $password, 'is_active' => true, 'must_change_password' => true]);
            }
            if (PanelMembership::query()->where('panel_user_id', $user->id)->where('brand_id', $brandId)->exists()) {
                throw ValidationException::withMessages(['email' => 'Bu e-posta bu markada zaten kayıtlı.']);
            }
            $membership = PanelMembership::query()->create(['panel_user_id' => $user->id, 'brand_id' => $brandId, 'access_role_id' => $roleId, 'is_manager' => $isManager, 'is_active' => true]);

            return [$membership, $password];
        });
    }

    public function resetPassword(PanelUser $user): string
    {
        $password = self::temporaryPassword();
        $user->forceFill(['password' => $password, 'must_change_password' => true])->save();

        return $password;
    }

    public function panelRole(?int $roleId): ?AccessRole
    {
        return $roleId ? AccessRole::query()->where('scope', PanelPermissions::SCOPE_PANEL)->find($roleId) : null;
    }

    /** Easy to read aloud: no look-alike characters. */
    public static function temporaryPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return Str::of($out)->substr(0, 5).'-'.Str::of($out)->substr(5);
    }
}

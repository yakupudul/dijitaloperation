<?php

/**
 * Restore the isolated E2E operator if the QA SQLite has schema but no login user.
 * Password is read from the local secret file and never printed.
 */

use App\Models\User;
use App\Support\Roles;
use Database\Seeders\ModuleRegistrySeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$email = getenv('MOXDOP_E2E_EMAIL') ?: 'qa-final@moxdop.local';
$passwordFile = getenv('MOXDOP_E2E_PASSWORD_FILE') ?: '/tmp/moxdop-final-manual-qa-admin.secret';

if (! Schema::hasTable('users')) {
    fwrite(STDERR, "QA database is missing the users table.\n");
    exit(1);
}

if (! is_readable($passwordFile)) {
    fwrite(STDERR, "QA password source is not readable.\n");
    exit(1);
}

$raw = (string) file_get_contents($passwordFile);
$lines = preg_split('/\r?\n/', $raw) ?: [];
$password = trim(implode('', array_values(array_filter(
    array_map(static fn (string $line): string => rtrim($line, "\r"), $lines),
    static fn (string $line): bool => $line !== '',
))));

if ($password === '') {
    fwrite(STDERR, "QA password source is empty.\n");
    exit(1);
}

(new RoleAndPermissionSeeder)->run();
(new ModuleRegistrySeeder)->run();
Role::findOrCreate(Roles::ADMIN, 'web');
Role::findOrCreate(Roles::TEAM_MEMBER, 'web');

$user = User::query()->where('email', $email)->first();

if ($user === null) {
    $user = User::query()->create([
        'name' => 'QA Final',
        'email' => $email,
        'password' => Hash::make($password),
        'is_active' => true,
        'locale' => 'en',
    ]);
} else {
    $user->forceFill([
        'is_active' => true,
        'password' => Hash::make($password),
    ])->save();
}

if (! $user->hasRole(Roles::ADMIN)) {
    $user->assignRole(Roles::ADMIN);
}

$ok = Auth::attempt(['email' => $email, 'password' => $password]);
Auth::logout();

if (! $ok) {
    fwrite(STDERR, "QA admin restore succeeded as a row but Auth::attempt failed.\n");
    exit(1);
}

fwrite(STDOUT, "QA admin ready: {$email}\n");
exit(0);

<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\OrganizationStructureSeeder;
use Database\Seeders\WeeklyPlanningPermissionSeeder;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Fixtures\WeeklyPlanningV3E2eFixture;
use Tests\Support\WeeklyPlanV3E2eRuntimeSafety;
use Tests\Support\WeeklyPlanV3IsolatedTestEnvironment;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$preflight = new Process([PHP_BINARY, $root.'/tests/Infrastructure/weekly-plan-v3-environment-preflight.php', 'e2e'], $root);
$preflight->mustRun();
fwrite(STDOUT, $preflight->getOutput());

Dotenv::createImmutable($root, '.env.weekly-planning-v3-e2e')->load();
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
WeeklyPlanV3IsolatedTestEnvironment::assertSafe(
    WeeklyPlanV3E2eRuntimeSafety::violations($app['config']->all(), $root, $app->configurationIsCached()),
);

$databasePath = $root.'/'.WeeklyPlanV3IsolatedTestEnvironment::E2E_DATABASE;
$directory = dirname($databasePath);
if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
    throw new RuntimeException('Cannot create the exact isolated E2E database directory.');
}
$temporaryDirectory = $root.'/'.WeeklyPlanV3E2eRuntimeSafety::ROOT.'/tmp';
if (! is_dir($temporaryDirectory) && ! mkdir($temporaryDirectory, 0700, true) && ! is_dir($temporaryDirectory)) {
    throw new RuntimeException('Cannot create the isolated E2E temporary directory.');
}
if (file_exists($databasePath) || is_link($databasePath)) {
    throw new RuntimeException('Refusing to replace an existing E2E database.');
}
if (! touch($databasePath)) {
    throw new RuntimeException('Cannot create the exact isolated E2E database.');
}

$identity = new PDO('sqlite:'.$databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$identity->exec('PRAGMA application_id = '.WeeklyPlanV3E2eRuntimeSafety::DATABASE_APPLICATION_ID);
$identity = null;

foreach ([
    ['migrate', ['--force' => true, '--no-interaction' => true]],
    ['db:seed', ['--class' => AuthorizationSeeder::class, '--force' => true, '--no-interaction' => true]],
    ['db:seed', ['--class' => OrganizationStructureSeeder::class, '--force' => true, '--no-interaction' => true]],
    ['db:seed', ['--class' => WeeklyPlanningPermissionSeeder::class, '--force' => true, '--no-interaction' => true]],
] as [$command, $arguments]) {
    if ($kernel->call($command, $arguments) !== 0) {
        throw new RuntimeException("Isolated E2E setup failed during {$command}.");
    }
}

$organization = Organization::query()->where('code', 'KPS')->firstOrFail();
$organization->update(['name' => WeeklyPlanningV3E2eFixture::ORGANIZATION['name']]);

foreach ([...WeeklyPlanningV3E2eFixture::ACCOUNTS, ...WeeklyPlanningV3E2eFixture::EMPLOYEES] as $record) {
    $roleCode = $record['primary_role'] ?? $record['role'];
    $role = Role::query()->where('code', $roleCode)->firstOrFail();
    $department = isset($record['department'])
        ? Department::query()->where('organization_id', $organization->id)->where('code', $record['department'])->firstOrFail()
        : null;
    $user = User::query()->create([
        'name' => $record['name'],
        'email' => $record['email'],
        'password' => WeeklyPlanningV3E2eFixture::PASSWORD,
    ]);
    $user->forceFill(['email_verified_at' => now()])->save();
    $membership = OrganizationMembership::query()->create([
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'department_id' => $department?->id,
        'is_default' => true,
        'is_active' => true,
    ]);
    $membership->roles()->attach($role->id, ['is_primary' => true]);
    foreach ($record['secondary_roles'] ?? [] as $secondaryRoleCode) {
        $secondaryRole = Role::query()->where('code', $secondaryRoleCode)->firstOrFail();
        $membership->roles()->attach($secondaryRole->id, ['is_primary' => false]);
    }
}

DB::statement('CREATE TABLE e2e_fixture_identity (name VARCHAR(64) PRIMARY KEY, value VARCHAR(128) NOT NULL)');
DB::table('e2e_fixture_identity')->insert([
    'name' => 'fixture_version',
    'value' => WeeklyPlanV3E2eRuntimeSafety::FIXTURE_VERSION,
]);

fwrite(STDOUT, "PASS: synthetic fixture created in the verified isolated SQLite database.\n");

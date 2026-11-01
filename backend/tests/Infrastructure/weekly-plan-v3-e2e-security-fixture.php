<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\WeeklyPlanV3E2eRuntimeSafety;
use Tests\Support\WeeklyPlanV3IsolatedTestEnvironment;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$action = $argv[1] ?? '';
if (! in_array($action, ['restore', 'wrong-organization', 'revoke-membership', 'revoke-permission', 'invalidate-session'], true)) {
    throw new RuntimeException('Only closed synthetic security fixture operations are allowed.');
}
(new Process([PHP_BINARY, $root.'/tests/Infrastructure/weekly-plan-v3-environment-preflight.php', 'e2e', 'verified-existing'], $root))->mustRun();
Dotenv::createImmutable($root, '.env.weekly-planning-v3-e2e')->load();
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.weekly-planning-v3-e2e');
$app->make(Kernel::class)->bootstrap();
WeeklyPlanV3IsolatedTestEnvironment::assertSafe(WeeklyPlanV3E2eRuntimeSafety::violations($app['config']->all(), $root, $app->configurationIsCached(), true));
$user = User::query()->where('email', 'security-assistant@weekly-plan-v3-e2e.example.test')->firstOrFail();
$membership = $user->organizationMemberships()->firstOrFail();
DB::transaction(function () use ($action, $user, $membership): void {
    if ($action === 'invalidate-session') {
        DB::table('sessions')->where('user_id', $user->id)->delete();

        return;
    }
    $organization = Organization::query()->where('code', 'KPS')->firstOrFail();
    if ($action === 'wrong-organization') {
        $organization = Organization::query()->firstOrCreate(['code' => 'V3-E2E-OTHER'], ['name' => 'Other Synthetic E2E Organization (Fake)']);
    }
    $membership->forceFill(['organization_id' => $organization->id, 'department_id' => null, 'is_active' => $action !== 'revoke-membership'])->save();
    $role = Role::query()->where('code', $action === 'revoke-permission' ? 'OFFICE_STAFF' : 'EXECUTIVE_ASSISTANT')->firstOrFail();
    $membership->roles()->sync([$role->id => ['is_primary' => true]]);
});
echo "PASS: closed synthetic security fixture updated.\n";

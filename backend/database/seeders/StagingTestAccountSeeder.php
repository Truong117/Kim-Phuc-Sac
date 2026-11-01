<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Services\KpsMembershipResolver;
use App\Support\StagingBootstrapGuard;
use App\Support\StagingBootstrapPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class StagingTestAccountSeeder extends Seeder
{
    public function run(): void
    {
        $connection = DB::connection();
        StagingBootstrapGuard::assertCanSeed(
            appEnvironment: (string) config('app.env'),
            databaseEnvironment: config('environment_safety.database_environment'),
            databaseDriver: $connection->getDriverName(),
            databaseName: (string) $connection->getDatabaseName(),
            seedEnabled: getenv('KPS_STAGING_BOOTSTRAP_SEED') === 'true',
            runningUnitTests: app()->runningUnitTests(),
        );

        $password = getenv('KPS_STAGING_TEST_ACCOUNT_PASSWORD');

        if (! is_string($password) || strlen($password) < 16 || strlen($password) > 72) {
            throw new LogicException('The staging test-account password must contain 16 to 72 bytes.');
        }

        $organization = Organization::query()
            ->where('code', KpsMembershipResolver::ORGANIZATION_CODE)
            ->firstOrFail();
        $roles = Role::query()
            ->whereIn('code', array_column(StagingBootstrapPlan::TEST_ACCOUNTS, 'role'))
            ->get()
            ->keyBy('code');
        $departments = Department::query()
            ->where('organization_id', $organization->id)
            ->whereIn('code', array_values(array_filter(
                array_column(StagingBootstrapPlan::TEST_ACCOUNTS, 'department'),
            )))
            ->get()
            ->keyBy('code');

        foreach (StagingBootstrapPlan::TEST_ACCOUNTS as $account) {
            $role = $roles->get($account['role']);

            if (! $role instanceof Role) {
                throw new LogicException("Missing staging fixture role: {$account['role']}");
            }

            $department = $account['department'] === null
                ? null
                : $departments->get($account['department']);

            if ($account['department'] !== null && ! $department instanceof Department) {
                throw new LogicException("Missing staging fixture department: {$account['department']}");
            }

            $user = User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => $password,
                ],
            );
            $user->forceFill(['email_verified_at' => now()])->save();
            $membership = OrganizationMembership::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'organization_id' => $organization->id,
                ],
                [
                    'department_id' => $department?->id,
                    'location_id' => null,
                    'is_default' => true,
                    'is_active' => true,
                ],
            );

            $membership->roles()->sync([
                $role->id => ['is_primary' => true],
            ]);
        }
    }
}

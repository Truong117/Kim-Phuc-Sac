<?php

namespace Database\Seeders;

use App\Support\StagingBootstrapGuard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StagingSeeder extends Seeder
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

        DB::transaction(function (): void {
            $this->call([
                AuthorizationSeeder::class,
                OrganizationStructureSeeder::class,
                TaskManagementPermissionSeeder::class,
                AiExecutiveDashboardPermissionSeeder::class,
                WeeklyPlanningPermissionSeeder::class,
                StagingTestAccountSeeder::class,
            ]);
        });
    }
}

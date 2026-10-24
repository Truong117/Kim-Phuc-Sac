<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\StagingBootstrapPlan;
use Database\Seeders\StagingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StagingSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_staging_seeder_is_idempotent_and_creates_only_fake_accounts(): void
    {
        $password = 'fake-staging-password-2026';
        putenv("KPS_STAGING_TEST_ACCOUNT_PASSWORD={$password}");

        try {
            $this->seed(StagingSeeder::class);
            $this->seed(StagingSeeder::class);
        } finally {
            putenv('KPS_STAGING_TEST_ACCOUNT_PASSWORD');
        }

        $this->assertDatabaseCount('users', count(StagingBootstrapPlan::TEST_ACCOUNTS));
        $this->assertSame(0, User::query()
            ->where('email', 'not like', '%.example.test')
            ->count());

        foreach (StagingBootstrapPlan::TEST_ACCOUNTS as $account) {
            $user = User::query()->where('email', $account['email'])->firstOrFail();

            $this->assertTrue(Hash::check($password, $user->password));
            $this->assertDatabaseHas('organization_memberships', [
                'user_id' => $user->id,
                'is_default' => true,
                'is_active' => true,
            ]);
            $this->assertDatabaseHas('membership_roles', [
                'membership_id' => $user->defaultOrganizationMembership()->value('id'),
                'role_id' => $user->defaultOrganizationMembership()
                    ->firstOrFail()
                    ->roles()
                    ->where('roles.code', $account['role'])
                    ->value('roles.id'),
                'is_primary' => true,
            ]);
        }

        foreach (StagingBootstrapPlan::REQUIRED_EXTENSION_PERMISSION_CODES as $permissionCode) {
            $this->assertDatabaseHas('permissions', ['code' => $permissionCode]);
        }
    }
}

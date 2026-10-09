<?php

namespace Tests\Feature;

use Database\Seeders\OrganizationStructureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationStructureSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seed_is_idempotent_and_creates_exactly_the_six_initial_kps_departments(): void
    {
        $this->seed();
        $this->seed();

        $organizationId = DB::table('organizations')->where('code', 'KPS')->value('id');

        $this->assertNotNull($organizationId);
        $this->assertDatabaseCount('departments', 6);
        $this->assertDatabaseCount('locations', 0);

        $expectedDepartments = [
            'HR_ADMIN' => 'Hành chính nhân sự',
            'MARKETING' => 'Marketing',
            'MEDIA' => 'Media',
            'ACCOUNTING' => 'Kế toán',
            'SALES' => 'Sales',
            'IT' => 'IT',
        ];

        foreach ($expectedDepartments as $code => $name) {
            $this->assertDatabaseHas('departments', [
                'organization_id' => $organizationId,
                'code' => $code,
                'name' => $name,
                'is_active' => true,
            ]);
        }
    }

    public function test_structure_seed_does_not_delete_departments_added_later_or_create_locations(): void
    {
        $this->seed();

        $organizationId = DB::table('organizations')->where('code', 'KPS')->value('id');
        $now = now();

        DB::table('departments')->insert([
            'organization_id' => $organizationId,
            'code' => 'FUTURE_DEPARTMENT',
            'name' => 'Phòng ban tương lai',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->seed(OrganizationStructureSeeder::class);

        $this->assertDatabaseCount('departments', 7);
        $this->assertDatabaseHas('departments', [
            'organization_id' => $organizationId,
            'code' => 'FUTURE_DEPARTMENT',
        ]);
        $this->assertDatabaseCount('locations', 0);
    }
}

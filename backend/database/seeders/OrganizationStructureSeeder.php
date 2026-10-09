<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Organization;
use App\Services\KpsMembershipResolver;
use Illuminate\Database\Seeder;
use LogicException;

class OrganizationStructureSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const DEPARTMENTS = [
        'HR_ADMIN' => 'Hành chính nhân sự',
        'MARKETING' => 'Marketing',
        'MEDIA' => 'Media',
        'ACCOUNTING' => 'Kế toán',
        'SALES' => 'Sales',
        'IT' => 'IT',
    ];

    public function run(): void
    {
        $organization = Organization::query()
            ->where('code', KpsMembershipResolver::ORGANIZATION_CODE)
            ->first();

        if ($organization === null) {
            throw new LogicException('The KPS organization must be seeded before its structure.');
        }

        foreach (self::DEPARTMENTS as $code => $name) {
            Department::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'code' => $code,
                ],
                [
                    'name' => $name,
                    'is_active' => true,
                ],
            );
        }
    }
}

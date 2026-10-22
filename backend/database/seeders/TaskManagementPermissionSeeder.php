<?php

namespace Database\Seeders;

use App\Enums\DataScope;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class TaskManagementPermissionSeeder extends Seeder
{
    /** @var array<string, DataScope> */
    private const ROLE_SCOPES = [
        'OWNER' => DataScope::ALL,
        'ADMIN' => DataScope::ALL,
        'HR_MANAGER' => DataScope::OWN,
        'DEPARTMENT_MANAGER' => DataScope::DEPARTMENT,
        'OFFICE_STAFF' => DataScope::OWN,
        'SALES_MANAGER' => DataScope::TEAM,
        'SALES_STAFF' => DataScope::OWN,
        'KPS_SPA_MANAGER' => DataScope::LOCATION,
        'KPS_SPA_STAFF' => DataScope::OWN,
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $permission = Permission::query()->updateOrCreate(
                ['code' => 'tasks.status.update'],
                [
                    'name' => 'Cập nhật trạng thái công việc được giao',
                    'module' => 'tasks',
                ],
            );
            $roles = Role::query()
                ->whereIn('code', array_keys(self::ROLE_SCOPES))
                ->get()
                ->keyBy('code');

            foreach (self::ROLE_SCOPES as $roleCode => $scope) {
                $role = $roles->get($roleCode);

                if (! $role instanceof Role) {
                    throw new LogicException("Missing required role: {$roleCode}");
                }

                $role->permissions()->syncWithoutDetaching([
                    $permission->id => ['data_scope' => $scope->value],
                ]);
            }
        });
    }
}

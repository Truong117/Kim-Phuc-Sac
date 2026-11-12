<?php

namespace Database\Seeders;

use App\Enums\DataScope;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/** Optional, additive provisioning for dedicated spa jobs; never rewrites existing grants. */
class WorkspacePermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'workspace.internal.access' => 'Truy cập giao diện nội bộ',
            'workspace.spa.access' => 'Truy cập giao diện Spa',
            'spa.technician.view' => 'Xem giao diện công việc KTV Spa',
        ] as $code => $name) {
            Permission::query()->firstOrCreate(['code' => $code], ['name' => $name, 'module' => 'spa']);
        }
        foreach ([
            'KPS_SPA_TECHNICIAN' => ['Kỹ thuật viên Spa', ['workspace.spa.access', 'spa.technician.view']],
            'KPS_SPA_RECEPTION' => ['Lễ tân Spa', ['workspace.spa.access', 'spa.appointments.view', 'spa.appointments.manage', 'spa.customers.view']],
            'KPS_SPA_DISPATCH' => ['Tư vấn và điều phối Spa', ['workspace.spa.access', 'spa.appointments.view', 'spa.appointments.manage', 'spa.customers.view']],
        ] as $code => [$name, $permissions]) {
            $role = Role::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_system' => true]);
            $grants = [];
            foreach ($permissions as $permissionCode) {
                $permission = Permission::query()->where('code', $permissionCode)->firstOrFail();
                $grants[$permission->id] = ['data_scope' => str_starts_with($permissionCode, 'spa.') ? ($code === 'KPS_SPA_TECHNICIAN' ? DataScope::OWN->value : DataScope::LOCATION->value) : null];
            }
            $role->permissions()->syncWithoutDetaching($grants);
        }
    }
}

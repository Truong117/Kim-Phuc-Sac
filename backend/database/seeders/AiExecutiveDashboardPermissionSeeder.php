<?php

namespace Database\Seeders;

use App\Enums\DataScope;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class AiExecutiveDashboardPermissionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $permission = Permission::query()->updateOrCreate(
                ['code' => 'ai.dashboard.view'],
                [
                    'name' => 'Xem tổng quan AI điều hành',
                    'module' => 'ai',
                ],
            );

            $roles = Role::query()
                ->whereIn('code', ['OWNER', 'ADMIN'])
                ->get()
                ->keyBy('code');

            foreach (['OWNER', 'ADMIN'] as $roleCode) {
                $role = $roles->get($roleCode);

                if (! $role instanceof Role) {
                    throw new LogicException("Missing required role: {$roleCode}");
                }

                $role->permissions()->syncWithoutDetaching([
                    $permission->id => ['data_scope' => DataScope::ALL->value],
                ]);
            }
        });
    }
}

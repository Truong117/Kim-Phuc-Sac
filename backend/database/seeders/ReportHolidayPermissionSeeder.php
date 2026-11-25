<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Additive grants: safe to run on an existing installation without resetting other permissions. */
class ReportHolidayPermissionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $roles = Role::query()->whereIn('code', ['OWNER', 'ADMIN', 'EXECUTIVE_ASSISTANT', 'HR_MANAGER'])->get();
            if ($roles->count() !== 4) {
                throw new LogicException('Report holidays require the existing OWNER, ADMIN, EXECUTIVE_ASSISTANT and HR_MANAGER roles.');
            }
            $view = Permission::query()->firstOrCreate(['code' => 'settings.view'], ['name' => 'Xem cài đặt', 'module' => 'settings']);
            $manage = Permission::query()->firstOrCreate(['code' => 'report_holidays.manage'], ['name' => 'Quản lý ngày nghỉ báo cáo', 'module' => 'settings']);
            foreach ($roles as $role) {
                foreach ([$view, $manage] as $permission) {
                    DB::table('role_permissions')->insertOrIgnore([
                        'role_id' => $role->id, 'permission_id' => $permission->id, 'data_scope' => null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }
}

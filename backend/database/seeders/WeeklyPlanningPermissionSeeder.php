<?php

namespace Database\Seeders;

use App\Enums\DataScope;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class WeeklyPlanningPermissionSeeder extends Seeder
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        'weekly_plans.view' => 'Xem kế hoạch công việc tuần',
        'weekly_plans.manage_draft' => 'Tạo, nhập và chỉnh sửa kế hoạch tuần',
        'weekly_plans.submit' => 'Gửi kế hoạch tuần để xét duyệt',
        'weekly_plans.review' => 'Xét duyệt kế hoạch theo nhân viên',
        'weekly_plans.approve' => 'Phê duyệt cuối kế hoạch tuần',
        'weekly_plans.publish' => 'Phát hành kế hoạch tuần thành công việc',
        'weekly_plans.google_docs.configure' => 'Cấu hình thư mục Google Docs được phê duyệt',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $permissions = collect(self::PERMISSIONS)->mapWithKeys(
                fn (string $name, string $code): array => [
                    $code => Permission::query()->updateOrCreate(
                        ['code' => $code],
                        ['name' => $name, 'module' => 'weekly_plans'],
                    ),
                ],
            );

            $hr = Role::query()->where('code', 'HR_MANAGER')->first();
            $owner = Role::query()->where('code', 'OWNER')->first();
            $admin = Role::query()->where('code', 'ADMIN')->first();

            if (! $hr instanceof Role || ! $owner instanceof Role || ! $admin instanceof Role) {
                throw new LogicException('Missing required OWNER, ADMIN or HR_MANAGER role.');
            }

            foreach (['weekly_plans.view', 'weekly_plans.manage_draft', 'weekly_plans.submit'] as $code) {
                $hr->permissions()->syncWithoutDetaching([
                    $permissions[$code]->id => ['data_scope' => DataScope::ORGANIZATION->value],
                ]);
            }

            foreach (['weekly_plans.view', 'weekly_plans.review', 'weekly_plans.approve', 'weekly_plans.publish'] as $code) {
                $owner->permissions()->syncWithoutDetaching([
                    $permissions[$code]->id => ['data_scope' => DataScope::ALL->value],
                ]);
            }

            foreach (['weekly_plans.view', 'weekly_plans.google_docs.configure'] as $code) {
                $admin->permissions()->syncWithoutDetaching([
                    $permissions[$code]->id => ['data_scope' => DataScope::ALL->value],
                ]);
            }
        });
    }
}

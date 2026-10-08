<?php

namespace Database\Seeders;

use App\Enums\DataScope;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use LogicException;

class AuthorizationSeeder extends Seeder
{
    private const SCOPED_PERMISSIONS = [
        'dashboard.view',
        'reports.create',
        'reports.view',
        'reports.approve',
        'tasks.view',
        'tasks.manage',
        'customers.view',
        'customers.create',
        'customers.update',
        'customers.assign',
        'customers.export',
        'products.view',
        'products.manage',
        'orders.view',
        'orders.manage',
        'ai.insights.view',
        'employees.view',
        'employees.manage',
        'users.view',
        'users.create',
        'users.update',
        'users.disable',
        'users.assign_role',
        'audit.view',
        'spa.customers.view',
        'spa.customers.manage',
        'spa.appointments.view',
        'spa.appointments.manage',
        'spa.services.manage',
    ];

    public function run(): void
    {
        Organization::query()->updateOrCreate(
            ['code' => 'KPS'],
            [
                'name' => 'Kim Phục Sắc',
                'type' => 'internal',
                'is_active' => true,
            ],
        );

        $rolesByCode = collect($this->roles())
            ->mapWithKeys(function (array $attributes, string $code): array {
                $role = Role::query()->updateOrCreate(
                    ['code' => $code],
                    $attributes + ['is_system' => true],
                );

                return [$code => $role];
            });

        $permissionsByCode = collect($this->permissions())
            ->mapWithKeys(function (array $attributes, string $code): array {
                $permission = Permission::query()->updateOrCreate(
                    ['code' => $code],
                    $attributes,
                );

                return [$code => $permission];
            });

        foreach ($this->roleGrants(array_keys($this->permissions())) as $roleCode => $grants) {
            $role = $rolesByCode->get($roleCode);

            if (! $role instanceof Role) {
                throw new LogicException("Unknown seeded role: {$roleCode}");
            }

            $assignments = [];

            foreach ($grants as $permissionCode => $scope) {
                $permission = $permissionsByCode->get($permissionCode);

                if (! $permission instanceof Permission) {
                    throw new LogicException("Unknown seeded permission: {$permissionCode}");
                }

                $assignments[$permission->id] = [
                    'data_scope' => $scope?->value,
                ];
            }

            $role->permissions()->sync($assignments);
        }
    }

    /**
     * @return array<string, array{name: string, description: string}>
     */
    private function roles(): array
    {
        return [
            'OWNER' => [
                'name' => 'Chủ sở hữu',
                'description' => 'Toàn quyền sở hữu và quản trị hệ thống.',
            ],
            'ADMIN' => [
                'name' => 'Quản trị viên',
                'description' => 'Toàn quyền quản trị hệ thống.',
            ],
            'DEPARTMENT_MANAGER' => [
                'name' => 'Quản lý phòng ban',
                'description' => 'Quản lý công việc và báo cáo trong phòng ban.',
            ],
            'OFFICE_STAFF' => [
                'name' => 'Nhân viên văn phòng',
                'description' => 'Thực hiện công việc và báo cáo cá nhân.',
            ],
            'SALES_MANAGER' => [
                'name' => 'Quản lý kinh doanh',
                'description' => 'Quản lý hoạt động kinh doanh của đội ngũ.',
            ],
            'SALES_STAFF' => [
                'name' => 'Nhân viên kinh doanh',
                'description' => 'Thực hiện các nghiệp vụ kinh doanh được giao.',
            ],
            'KPS_SPA_MANAGER' => [
                'name' => 'Quản lý KPS Spa',
                'description' => 'Quản lý hoạt động tại địa điểm KPS Spa.',
            ],
            'KPS_SPA_STAFF' => [
                'name' => 'Nhân viên KPS Spa',
                'description' => 'Thực hiện các nghiệp vụ KPS Spa được giao.',
            ],
        ];
    }

    /**
     * @return array<string, array{name: string, module: string}>
     */
    private function permissions(): array
    {
        return [
            'dashboard.view' => ['name' => 'Xem tổng quan', 'module' => 'dashboard'],

            'reports.create' => ['name' => 'Tạo báo cáo', 'module' => 'reports'],
            'reports.view' => ['name' => 'Xem báo cáo', 'module' => 'reports'],
            'reports.approve' => ['name' => 'Phê duyệt báo cáo', 'module' => 'reports'],

            'tasks.view' => ['name' => 'Xem công việc', 'module' => 'tasks'],
            'tasks.manage' => ['name' => 'Quản lý công việc', 'module' => 'tasks'],

            'customers.view' => ['name' => 'Xem khách hàng', 'module' => 'customers'],
            'customers.create' => ['name' => 'Tạo khách hàng', 'module' => 'customers'],
            'customers.update' => ['name' => 'Cập nhật khách hàng', 'module' => 'customers'],
            'customers.assign' => ['name' => 'Phân công khách hàng', 'module' => 'customers'],
            'customers.export' => ['name' => 'Xuất dữ liệu khách hàng', 'module' => 'customers'],

            'products.view' => ['name' => 'Xem sản phẩm', 'module' => 'products'],
            'products.manage' => ['name' => 'Quản lý sản phẩm', 'module' => 'products'],

            'orders.view' => ['name' => 'Xem đơn hàng', 'module' => 'orders'],
            'orders.manage' => ['name' => 'Quản lý đơn hàng', 'module' => 'orders'],

            'ai.insights.view' => ['name' => 'Xem phân tích AI', 'module' => 'ai'],
            'ai.assistant.use' => ['name' => 'Sử dụng trợ lý AI', 'module' => 'ai'],

            'employees.view' => ['name' => 'Xem nhân viên', 'module' => 'employees'],
            'employees.manage' => ['name' => 'Quản lý nhân viên', 'module' => 'employees'],

            'users.view' => ['name' => 'Xem người dùng', 'module' => 'users'],
            'users.create' => ['name' => 'Tạo người dùng', 'module' => 'users'],
            'users.update' => ['name' => 'Cập nhật người dùng', 'module' => 'users'],
            'users.disable' => ['name' => 'Vô hiệu hóa người dùng', 'module' => 'users'],
            'users.assign_role' => ['name' => 'Gán vai trò người dùng', 'module' => 'users'],

            'organization.view' => ['name' => 'Xem tổ chức', 'module' => 'organization'],
            'organization.manage' => ['name' => 'Quản lý tổ chức', 'module' => 'organization'],

            'departments.view' => ['name' => 'Xem phòng ban', 'module' => 'departments'],
            'departments.manage' => ['name' => 'Quản lý phòng ban', 'module' => 'departments'],

            'integrations.view' => ['name' => 'Xem tích hợp', 'module' => 'integrations'],
            'integrations.manage' => ['name' => 'Quản lý tích hợp', 'module' => 'integrations'],

            'settings.view' => ['name' => 'Xem cài đặt', 'module' => 'settings'],
            'settings.manage' => ['name' => 'Quản lý cài đặt', 'module' => 'settings'],

            'audit.view' => ['name' => 'Xem nhật ký kiểm toán', 'module' => 'audit'],

            'spa.customers.view' => ['name' => 'Xem khách hàng Spa', 'module' => 'spa'],
            'spa.customers.manage' => ['name' => 'Quản lý khách hàng Spa', 'module' => 'spa'],
            'spa.appointments.view' => ['name' => 'Xem lịch hẹn Spa', 'module' => 'spa'],
            'spa.appointments.manage' => ['name' => 'Quản lý lịch hẹn Spa', 'module' => 'spa'],
            'spa.services.manage' => ['name' => 'Quản lý dịch vụ Spa', 'module' => 'spa'],
        ];
    }

    /**
     * @param  list<string>  $permissionCodes
     * @return array<string, array<string, DataScope|null>>
     */
    private function roleGrants(array $permissionCodes): array
    {
        $allPermissions = array_fill_keys($permissionCodes, null);

        foreach (self::SCOPED_PERMISSIONS as $permissionCode) {
            $allPermissions[$permissionCode] = DataScope::ALL;
        }

        return [
            'OWNER' => $allPermissions,
            'ADMIN' => $allPermissions,
            'DEPARTMENT_MANAGER' => $this->grants([
                'dashboard.view',
                'reports.create',
                'reports.view',
                'reports.approve',
                'tasks.view',
                'tasks.manage',
                'employees.view',
            ], DataScope::DEPARTMENT),
            'OFFICE_STAFF' => $this->grants([
                'dashboard.view',
                'reports.create',
                'reports.view',
                'tasks.view',
            ], DataScope::OWN),
            'SALES_MANAGER' => $this->grants([
                'dashboard.view',
                'reports.create',
                'reports.view',
                'tasks.view',
                'tasks.manage',
                'customers.view',
                'customers.create',
                'customers.update',
                'customers.assign',
                'customers.export',
                'orders.view',
                'orders.manage',
            ], DataScope::TEAM, [
                'products.view' => DataScope::ORGANIZATION,
                'ai.assistant.use' => null,
            ]),
            'SALES_STAFF' => $this->grants([
                'dashboard.view',
                'reports.create',
                'reports.view',
                'tasks.view',
                'customers.view',
                'customers.create',
                'customers.update',
                'orders.view',
                'orders.manage',
            ], DataScope::OWN, [
                'products.view' => DataScope::ORGANIZATION,
                'ai.assistant.use' => null,
            ]),
            'KPS_SPA_MANAGER' => $this->grants([
                'dashboard.view',
                'reports.create',
                'reports.view',
                'tasks.view',
                'tasks.manage',
                'customers.view',
                'customers.update',
                'spa.customers.view',
                'spa.customers.manage',
                'spa.appointments.view',
                'spa.appointments.manage',
                'spa.services.manage',
            ], DataScope::LOCATION),
            'KPS_SPA_STAFF' => $this->grants([
                'dashboard.view',
                'reports.create',
                'reports.view',
                'tasks.view',
                'customers.view',
                'spa.customers.view',
                'spa.appointments.view',
                'spa.appointments.manage',
            ], DataScope::OWN),
        ];
    }

    /**
     * @param  list<string>  $scopedPermissions
     * @param  array<string, DataScope|null>  $additionalPermissions
     * @return array<string, DataScope|null>
     */
    private function grants(
        array $scopedPermissions,
        DataScope $scope,
        array $additionalPermissions = [],
    ): array {
        return array_merge(
            array_fill_keys($scopedPermissions, $scope),
            $additionalPermissions,
        );
    }
}

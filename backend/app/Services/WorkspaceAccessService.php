<?php

namespace App\Services;

use App\Models\User;

/** UI workspace capabilities only; business endpoints retain their own permission checks. */
class WorkspaceAccessService
{
    public const SPA_SECTIONS = [
        'overview', 'appointments', 'reception', 'customers', 'services',
        'treatments', 'cashier', 'technician', 'rooms', 'inventory',
        'care', 'reports', 'settings',
    ];

    public function __construct(private readonly KpsMembershipResolver $memberships) {}

    /** @return list<array{id: string, home: string, sections: list<string>}> */
    public function forUser(User $user): array
    {
        $membership = $this->memberships->activeMembershipFor($user, ['roles.permissions']);
        if ($membership === null) {
            return [];
        }

        $permissions = $membership->roles->flatMap(fn ($role) => $role->permissions->pluck('code'))->unique();
        // Legacy office roles already carry internal permissions. A spa-only role's
        // legacy dashboard/report grants must not accidentally expose the office shell.
        $internal = $permissions->contains('workspace.internal.access') || $membership->roles->contains(
            fn ($role) => ! str_starts_with($role->code, 'KPS_SPA_') && $role->permissions->contains(
                fn ($permission) => ! str_starts_with($permission->code, 'spa.')
                    && ! str_starts_with($permission->code, 'workspace.'),
            ),
        );
        $spa = $permissions->contains('workspace.spa.access') || $permissions->contains(
            fn ($code) => str_starts_with($code, 'spa.'),
        );
        $result = [];
        if ($internal) {
            $result[] = ['id' => 'internal', 'home' => '/', 'sections' => []];
        }
        if ($spa) {
            $manager = $permissions->contains('spa.services.manage');
            $reception = $permissions->contains('spa.appointments.manage');
            $technician = $permissions->contains('spa.technician.view');
            $sections = $manager ? self::SPA_SECTIONS : array_values(array_unique(array_merge(
                $reception ? ['appointments', 'reception', 'customers', 'rooms', 'care'] : [],
                $technician ? ['technician'] : [],
                ! $reception && ! $technician ? ['appointments', 'customers'] : [],
            )));
            $home = $manager ? '/spa' : ($reception ? '/spa/reception' : ($technician ? '/spa/technician' : '/spa/appointments'));
            $result[] = ['id' => 'spa', 'home' => $home, 'sections' => $sections];
        }

        return $result;
    }
}

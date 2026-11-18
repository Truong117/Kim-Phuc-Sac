<?php

namespace App\Services\Spa;

use App\Enums\DataScope;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\KpsMembershipResolver;
use Illuminate\Support\Facades\Schema;

final class SpaBookingAccess
{
    /** Current grants for each action. Role assignments belong to KPS authorization. */
    private const PERMISSIONS = [
        'viewAppointments' => ['spa.appointments.view'],
        'createAppointment' => ['spa.appointments.view', 'spa.appointments.manage', 'spa.customers.view'],
        'rescheduleAppointment' => ['spa.appointments.view', 'spa.appointments.manage'],
        'cancelAppointment' => ['spa.appointments.view', 'spa.appointments.manage'],
        'viewCustomers' => ['spa.customers.view'],
        'createCustomer' => ['spa.customers.view', 'spa.customers.manage'],
        'editCustomer' => ['spa.customers.view', 'spa.customers.manage'],
    ];

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly KpsMembershipResolver $memberships,
    ) {}

    public function capabilities(User $user): array
    {
        $enabled = config('spa.booking_enabled') === true;

        return ['enabled' => $enabled, ...array_map(
            fn (array $permissions) => $enabled && $this->allows($user, $permissions),
            self::PERMISSIONS,
        )];
    }

    public function organizationFor(User $user, string ...$actions): int
    {
        $membership = $this->memberships->activeMembershipFor($user);
        abort_unless($membership, 403);
        foreach ($actions as $action) {
            abort_unless(isset(self::PERMISSIONS[$action]) && $this->allows($user, self::PERMISSIONS[$action]), 403);
        }
        abort_unless(config('spa.booking_enabled') === true, 409, 'SPA_BOOKING_DISABLED');
        foreach (SpaBookingService::DB_TABLES as $table) {
            abort_unless(Schema::hasTable($table), 409, 'SPA_BOOKING_MIGRATION_REQUIRED');
        }

        return (int) $membership->organization_id;
    }

    private function allows(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            // The first rollout is organization-wide. Narrower scopes need a defined data boundary.
            if (! in_array($this->authorization->scopeFor($user, $permission), [DataScope::ORGANIZATION, DataScope::ALL], true)) {
                return false;
            }
        }

        return true;
    }
}

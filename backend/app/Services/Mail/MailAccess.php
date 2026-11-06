<?php

namespace App\Services\Mail;

use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\KpsMembershipResolver;

class MailAccess
{
    public function __construct(private readonly KpsMembershipResolver $memberships) {}

    public function membership(User $user, bool $configure = false): ?OrganizationMembership
    {
        $membership = $this->memberships->activeMembershipFor($user, ['roles']);
        $roles = $configure ? ['OWNER', 'ADMIN'] : ['OWNER', 'ADMIN', 'EXECUTIVE_ASSISTANT'];

        return $membership !== null && $membership->roles->contains(fn ($role) => in_array($role->code, $roles, true)) ? $membership : null;
    }

    public function require(User $user, bool $configure = false): OrganizationMembership
    {
        $membership = $this->membership($user, $configure);
        abort_if($membership === null, 403);

        return $membership;
    }
}

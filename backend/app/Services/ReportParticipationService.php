<?php

namespace App\Services;

use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ReportParticipationService
{
    private const EXEMPT_ROLE_CODE = 'OWNER';

    public const NOT_PARTICIPANT_MESSAGE = 'Tài khoản này không thuộc đối tượng thực hiện báo cáo hằng ngày.';

    public function __construct(private readonly KpsMembershipResolver $memberships) {}

    public function isParticipant(User|OrganizationMembership $subject): bool
    {
        $membership = $subject instanceof User
            ? $this->memberships->activeMembershipFor($subject, ['roles:id,code'])
            : $subject->loadMissing('roles:id,code');

        return $membership !== null
            && ! $membership->roles->contains('code', self::EXEMPT_ROLE_CODE);
    }

    public function ensureParticipant(User|OrganizationMembership $subject): void
    {
        if (! $this->isParticipant($subject)) {
            throw new AccessDeniedHttpException(self::NOT_PARTICIPANT_MESSAGE);
        }
    }

    /**
     * @param  Builder<OrganizationMembership>  $query
     * @return Builder<OrganizationMembership>
     */
    public function applyParticipantScope(Builder $query): Builder
    {
        return $query->whereDoesntHave(
            'roles',
            fn (Builder $roles): Builder => $roles->where('roles.code', self::EXEMPT_ROLE_CODE),
        );
    }
}

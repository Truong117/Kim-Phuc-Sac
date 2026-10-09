<?php

namespace App\Services;

use App\Enums\DashboardMode;
use App\Enums\DataScope;
use App\Models\OrganizationMembership;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class DashboardModeResolver
{
    private const PERMISSION = 'dashboard.view';

    public function __construct(private readonly AuthorizationService $authorization) {}

    public function resolve(User $user, OrganizationMembership $membership): DashboardMode
    {
        $scopes = $this->authorization->scopesFor($user, self::PERMISSION);

        if ($this->contains($scopes, DataScope::ALL)
            || $this->contains($scopes, DataScope::ORGANIZATION)) {
            return DashboardMode::ORGANIZATION;
        }

        $hasDepartment = $this->contains($scopes, DataScope::DEPARTMENT);
        $hasLocation = $this->contains($scopes, DataScope::LOCATION);

        if ($hasDepartment && $hasLocation) {
            $this->deny();
        }

        if ($hasDepartment) {
            if ($membership->department_id === null
                || ! $this->containsOnly($scopes, [DataScope::DEPARTMENT, DataScope::SELF, DataScope::OWN])) {
                $this->deny();
            }

            return DashboardMode::DEPARTMENT;
        }

        if ($hasLocation) {
            if ($membership->location_id === null
                || ! $this->containsOnly($scopes, [DataScope::LOCATION, DataScope::SELF, DataScope::OWN])) {
                $this->deny();
            }

            return DashboardMode::LOCATION;
        }

        if ($scopes !== [] && $this->containsOnly($scopes, [DataScope::SELF, DataScope::OWN])) {
            return DashboardMode::PERSONAL;
        }

        $this->deny();
    }

    /** @param list<DataScope|null> $scopes */
    private function contains(array $scopes, DataScope $expected): bool
    {
        return in_array($expected, $scopes, true);
    }

    /**
     * @param  list<DataScope|null>  $scopes
     * @param  list<DataScope>  $allowed
     */
    private function containsOnly(array $scopes, array $allowed): bool
    {
        foreach ($scopes as $scope) {
            if ($scope === null || ! in_array($scope, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private function deny(): never
    {
        throw new AccessDeniedHttpException('Không thể xác định phạm vi Tổng quan.');
    }
}

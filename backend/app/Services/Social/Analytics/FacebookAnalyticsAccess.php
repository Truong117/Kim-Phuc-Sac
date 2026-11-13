<?php

namespace App\Services\Social\Analytics;

use App\Enums\DataScope;
use App\Models\IntegrationEntry;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\KpsMembershipResolver;
use Illuminate\Database\Eloquent\Builder;

class FacebookAnalyticsAccess
{
    public function enabled(): bool
    {
        return (bool) config('social_metrics.enabled') && (bool) config('facebook_analytics.enabled');
    }

    public function organization(User $user): int
    {
        return (int) app(KpsMembershipResolver::class)->activeMembershipFor($user)?->organization_id;
    }

    public function broad(User $user): bool
    {
        $auth = new AuthorizationService(new KpsMembershipResolver);

        return $auth->allows($user, 'integrations.manage') || ($auth->allows($user, 'integrations.view')
            && in_array($auth->scopeFor($user, 'reports.view'), [DataScope::ORGANIZATION, DataScope::ALL], true));
    }

    public function entries(User $user): Builder
    {
        return IntegrationEntry::query()->where('organization_id', $this->organization($user))->where('kind', 'social')
            ->where('platform', 'facebook')->where('enabled', true)
            ->when(! $this->broad($user), fn ($q) => $q->where('responsible_user_id', $user->id));
    }

    public function hasAssignedChannel(User $user): bool
    {
        return $this->enabled() && $this->entries($user)->where('responsible_user_id', $user->id)->exists();
    }

    public function visible(User $user): bool
    {
        return $this->enabled() && ($this->broad($user) || $this->entries($user)->exists());
    }

    public function entry(User $user, int $id): IntegrationEntry
    {
        abort_unless($this->enabled(), 404);

        return $this->entries($user)->findOrFail($id);
    }

    public function connection(IntegrationEntry $entry): SocialConnection
    {
        return SocialConnection::query()->where('organization_id', $entry->organization_id)->where('integration_entry_id', $entry->id)->firstOrFail();
    }

    public function principalAvailable(SocialConnection $connection): bool
    {
        $user = User::query()->find($connection->connected_by);
        $auth = new AuthorizationService(new KpsMembershipResolver);

        return $user !== null && $this->organization($user) === (int) $connection->organization_id
            && $auth->allows($user, 'integrations.manage') && $auth->allows($user, 'reports.view');
    }
}

<?php

namespace App\Services\Crm;

use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\CareServiceCatalog;
use App\Services\CustomerCare;
use App\Services\ReportClock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CrmCustomers
{
    public const CHANNELS = ['FANPAGE', 'ZALO', 'PHONE', 'REFERRAL', 'OTHER'];

    public const STAGES = ['NEW', 'CONSULTING', 'WAITING', 'WON', 'LOST'];

    public function __construct(
        private readonly CrmAccess $access,
        private readonly CustomerCare $care,
        private readonly ReportClock $clock,
    ) {}

    public function index(OrganizationMembership $member, ?string $search)
    {
        $query = $this->access->customers($member, 'customers.view');
        $term = trim($search ?? '');
        if ($term !== '') {
            try {
                $identity = CustomerCare::normalizeContact($term, 'search');
            } catch (ValidationException) {
                $identity = null;
            }
            $query->where(function (Builder $matches) use ($term, $identity, $member): void {
                $matches->where('customer.name', 'like', '%'.addcslashes($term, '%_\\').'%');
                if (preg_match('/^KH-(\d+)$/i', $term, $match)) {
                    $matches->orWhere('customer.id', (int) $match[1]);
                }
                if ($identity !== null) {
                    $matches->orWhereIn('customer.id', DB::table('care_customer_contacts')
                        ->where('organization_id', $member->organization_id)
                        ->where('identity_hash', hash('sha256', $identity))->select('customer_id'));
                }
            });
        }

        return $this->withOwner($query)->orderByDesc('customer.id')->paginate(25)
            ->through(fn (object $customer) => $this->publicFields($customer));
    }

    public function detail(OrganizationMembership $member, int $id): array
    {
        $customer = $this->withOwner($this->access->customers($member, 'customers.view'))->where('customer.id', $id)->first();
        abort_unless($customer, 404);

        return [
            ...$this->publicFields($customer),
            'can_update' => $this->access->customers($member, 'customers.update')->where('customer.id', $id)->exists(),
            'can_assign' => $this->access->customers($member, 'customers.assign')->where('customer.id', $id)->exists(),
            'contacts' => DB::table('care_customer_contacts')->where('organization_id', $member->organization_id)
                ->where('customer_id', $id)->orderBy('id')->get(['channel', 'value']),
            'opportunities' => DB::table('crm_opportunities')->where('customer_id', $id)->orderByDesc('id')->limit(50)
                ->get(['id', 'title', 'stage', 'resolution', 'version']),
            'activities' => DB::table('crm_activities as activity')->join('users', 'users.id', '=', 'activity.actor_id')
                ->where('activity.organization_id', $member->organization_id)->where('activity.customer_id', $id)
                ->orderByDesc('activity.id')->limit(50)->get([
                    'activity.id', 'activity.kind', 'activity.title', 'activity.result', 'activity.services',
                    'activity.business_date', 'activity.due_on', 'users.name as actor_name',
                ])->map(function (object $activity): object {
                    $activity->services = json_decode($activity->services ?? '[]', true, flags: JSON_THROW_ON_ERROR);

                    return $activity;
                }),
        ];
    }

    public function create(OrganizationMembership $member, array $input): int
    {
        abort_unless($this->access->scopes($member, 'customers.create') !== [], 403);

        return DB::transaction(function () use ($member, $input): int {
            $previous = $this->replay($member, $input);
            if ($previous) {
                abort_unless($this->access->customers($member, 'customers.view')->where('customer.id', $previous->customer_id)->exists(), 404);

                return (int) $previous->customer_id;
            }

            $id = (int) $this->care->createCustomer($member->user, (int) $member->organization_id, $input, 'contact', reuseRequest: false)->id;
            DB::table('crm_customer_profiles')->insert([
                'customer_id' => $id, 'owner_membership_id' => $member->id, 'department_id' => $member->department_id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->activity($member, $id, $input, 'INTAKE', 'Tiếp nhận khách mới', $input['channel']);

            return $id;
        }, 3);
    }

    public function record(OrganizationMembership $member, int $id, array $input): int
    {
        return DB::transaction(function () use ($member, $id, $input): int {
            $identity = ['customer_id' => $id, ...$input];
            $previous = $this->replay($member, $identity);
            if ($previous) {
                return (int) $previous->id;
            }

            $this->lockCustomer($member, $id, 'customers.update');
            $kind = $input['kind'];
            if ($kind === 'OPPORTUNITY') {
                $opportunity = DB::table('crm_opportunities')->insertGetId([
                    'customer_id' => $id, 'title' => $input['title'], 'created_at' => now(), 'updated_at' => now(),
                ]);

                return $this->activity($member, $id, $identity, $kind, $input['title'], null, opportunity: $opportunity);
            }
            if ($kind === 'FOLLOW_UP') {
                if ($input['due_on'] < $this->clock->businessDate()) {
                    throw ValidationException::withMessages(['due_on' => 'Lịch liên hệ lại phải từ hôm nay trở đi.']);
                }

                return $this->activity($member, $id, $identity, $kind, $input['title'], null, due: $input['due_on']);
            }

            $services = array_map(fn (string $name) => ['name' => trim($name)], $input['services']);
            $keys = array_map(CareServiceCatalog::key(...), $services);
            if (count($keys) !== count(array_unique($keys))) {
                throw ValidationException::withMessages(['services' => 'Mỗi nội dung hỗ trợ chỉ chọn một lần.']);
            }

            return $this->activity($member, $id, $identity, $kind, 'Tư vấn khách hàng', $input['result'], $services);
        }, 3);
    }

    public function assign(OrganizationMembership $member, int $id, array $input): void
    {
        DB::transaction(function () use ($member, $id, $input): void {
            if ($this->replay($member, ['customer_id' => $id, ...$input])) {
                return;
            }
            $this->lockCustomer($member, $id, 'customers.assign');
            $profile = DB::table('crm_customer_profiles')->where('customer_id', $id)->first();
            abort_if((int) ($profile->version ?? 0) !== (int) $input['version'], 409, 'Hồ sơ vừa đổi. Tải lại trước khi bàn giao.');
            $recipient = $this->access->assignees($member)->where('membership.id', $input['membership_id'])
                ->first(['membership.id', 'membership.department_id', 'users.name']);
            if (! $recipient) {
                throw ValidationException::withMessages(['membership_id' => 'Người nhận không thuộc phạm vi được giao.']);
            }

            DB::table('crm_customer_profiles')->updateOrInsert(['customer_id' => $id], [
                'owner_membership_id' => $recipient->id, 'department_id' => $recipient->department_id,
                'version' => (int) ($profile->version ?? 0) + 1, 'updated_at' => now(),
            ]);
            $this->activity($member, $id, ['customer_id' => $id, ...$input], 'HANDOFF', 'Bàn giao cho '.$recipient->name, $input['reason']);
        }, 3);
    }

    public function stage(OrganizationMembership $member, int $id, int $opportunityId, array $input): void
    {
        DB::transaction(function () use ($member, $id, $opportunityId, $input): void {
            $this->lockCustomer($member, $id, 'customers.update');
            $query = DB::table('crm_opportunities')->where('customer_id', $id)->where('id', $opportunityId);
            $opportunity = $query->first();
            abort_unless($opportunity, 404);
            abort_if((int) $opportunity->version !== (int) $input['version'], 409, 'Đợt tư vấn vừa đổi. Tải lại trước khi cập nhật.');

            $allowed = match ($opportunity->stage) {
                'NEW' => ['CONSULTING', 'LOST'],
                'CONSULTING' => ['WAITING', 'WON', 'LOST'],
                'WAITING' => ['CONSULTING', 'WON', 'LOST'],
                default => [],
            };
            if (! in_array($input['stage'], $allowed, true)) {
                throw ValidationException::withMessages(['stage' => 'Chọn bước tiếp theo phù hợp với đợt tư vấn.']);
            }
            if ($input['stage'] === 'WAITING' && ! DB::table('crm_activities')->where('customer_id', $id)->where('kind', 'FOLLOW_UP')
                ->where('due_on', '>=', $this->clock->businessDate())->exists()) {
                throw ValidationException::withMessages(['stage' => 'Đặt lịch liên hệ lại trước khi chuyển sang Chờ phản hồi.']);
            }
            $query->update([
                'stage' => $input['stage'],
                'resolution' => in_array($input['stage'], ['WON', 'LOST'], true) ? $input['resolution'] : null,
                'version' => (int) $opportunity->version + 1, 'updated_at' => now(),
            ]);
        }, 3);
    }

    private function withOwner(Builder $query): Builder
    {
        return $query->leftJoin('organization_memberships as owner', 'owner.id', '=', 'profile.owner_membership_id')
            ->leftJoin('users as assigned', 'assigned.id', '=', 'owner.user_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'customer.created_by')
            ->select('customer.id', 'customer.name', 'profile.version', 'profile.owner_membership_id')
            ->selectRaw('CASE WHEN profile.customer_id IS NULL THEN creator.name ELSE assigned.name END as owner_name')
            ->addSelect(['channel' => DB::table('care_customer_contacts')->whereColumn('customer_id', 'customer.id')
                ->whereColumn('organization_id', 'customer.organization_id')->orderBy('id')->limit(1)->select('channel')]);
    }

    private function publicFields(object $customer): array
    {
        return [
            'id' => (int) $customer->id, 'code' => 'KH-'.$customer->id, 'name' => $customer->name,
            'channel' => $customer->channel, 'owner_name' => $customer->owner_name,
            'owner_membership_id' => $customer->owner_membership_id === null ? null : (int) $customer->owner_membership_id,
            'version' => (int) ($customer->version ?? 0),
        ];
    }

    private function lockCustomer(OrganizationMembership $member, int $id, string $permission): void
    {
        $visible = $this->access->customers($member, 'customers.view')->where('customer.id', $id)->lockForUpdate()->first(['customer.id']);
        abort_unless($visible, 404);
        $writable = $this->access->customers($member, $permission)->where('customer.id', $id)->lockForUpdate()->first(['customer.id']);
        abort_unless($writable, 403);
    }

    private function replay(OrganizationMembership $member, array $input): ?object
    {
        User::query()->whereKey($member->user_id)->lockForUpdate()->firstOrFail();
        $record = DB::table('crm_activities')->where('organization_id', $member->organization_id)
            ->where('actor_id', $member->user_id)->where('request_key', $input['request_key'])->first();
        abort_if($record && $record->request_hash !== $this->requestHash($input), 409, 'Thao tác đã dùng cho dữ liệu khác.');

        return $record;
    }

    private function requestHash(array $input): string
    {
        ksort($input);

        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }

    private function activity(OrganizationMembership $member, int $id, array $input, string $kind, string $title,
        ?string $result, ?array $services = null, ?int $opportunity = null, ?string $due = null): int
    {
        return DB::table('crm_activities')->insertGetId([
            'organization_id' => $member->organization_id, 'actor_id' => $member->user_id, 'customer_id' => $id,
            'opportunity_id' => $opportunity, 'kind' => $kind, 'title' => $title, 'result' => $result,
            'business_date' => $this->clock->businessDate(), 'due_on' => $due,
            'services' => $services === null ? null : json_encode($services, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'request_key' => $input['request_key'], 'request_hash' => $this->requestHash($input), 'created_at' => now(),
        ]);
    }
}

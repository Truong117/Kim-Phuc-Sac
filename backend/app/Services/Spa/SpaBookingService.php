<?php

namespace App\Services\Spa;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

final class SpaBookingService
{
    public const DB_TABLES = ['spa_customers', 'spa_appointments', 'spa_appointment_events'];

    public function customers(int $organizationId, ?string $search, int $page): array
    {
        $query = $this->customerQuery($organizationId);
        $search = trim($search ?? '');
        if ($search !== '') {
            $query->where(function (Builder $matches) use ($search): void {
                // Search literal characters, including SQL wildcard characters.
                $term = strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']);
                $matches->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$term.'%']);
                $phone = $this->normalizePhone($search);
                if (ctype_digit($phone)) {
                    $matches->orWhere('phone', 'like', '%'.$phone.'%');
                }
            });
        }

        return $this->page($query->orderBy('name')->orderBy('id')->paginate(20, ['*'], 'page', $page), $this->presentCustomer(...));
    }

    public function findCustomerByPhone(int $organizationId, string $phone): ?array
    {
        $row = $this->customerQuery($organizationId)->where('phone', $this->validatedPhone($phone))->first();

        return $row ? $this->presentCustomer($row) : null;
    }

    public function createCustomer(int $organizationId, int $actorId, array $input): array
    {
        $id = $this->insertCustomer($organizationId, $actorId, $this->customerValues($input));

        return $this->presentCustomer($this->customerQuery($organizationId)->where('id', $id)->first());
    }

    public function updateCustomer(int $organizationId, int $actorId, string $id, array $input): array
    {
        $values = $this->customerValues($input);
        $query = $this->customerQuery($organizationId)->where('id', $id);
        try {
            return DB::transaction(function () use ($query, $values, $input, $actorId): array {
                $before = (clone $query)->lockForUpdate()->first();
                abort_unless($before, 404);
                abort_unless((int) $before->version === (int) $input['version'], 409, 'SPA_CUSTOMER_STALE');
                $query->update([
                    ...$values,
                    'version' => (int) $before->version + 1,
                    'updated_by' => $actorId,
                    'updated_at' => now(),
                ]);

                return $this->presentCustomer($query->first());
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $this->duplicatePhone('phone');
        }
    }

    public function services(int $organizationId, ?string $search, int $page): array
    {
        $query = $this->servicesQuery($organizationId);
        $search = trim($search ?? '');
        if ($search !== '') {
            $term = strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']);
            $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$term.'%']);
        }

        return $this->page(
            $query->orderBy('name')->orderBy('id')->paginate(20, ['id', 'name', 'price', 'duration_minutes', 'requires_skin_photo'], 'page', $page),
            fn (stdClass $row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'price' => (int) $row->price,
                'duration_minutes' => (int) $row->duration_minutes,
                'requires_skin_photo' => (bool) $row->requires_skin_photo,
            ],
        );
    }

    public function appointments(int $organizationId, string $date, int $page = 1): array
    {
        $query = $this->appointmentsWithCustomer($organizationId)->where('spa_appointments.date', $date)
            ->orderBy('spa_appointments.start_time')->orderBy('spa_appointments.id');

        return $this->page($query->paginate(25, ['*'], 'page', $page), $this->presentAppointment(...));
    }

    public function appointment(int $organizationId, string $id): array
    {
        $row = $this->appointmentsWithCustomer($organizationId)->where('spa_appointments.id', $id)->first();
        abort_unless($row, 404);
        $events = DB::table('spa_appointment_events')->where('appointment_id', $id)
            ->where('version', '<=', $row->version)->orderBy('version')->get();

        return [
            ...$this->presentAppointment($row),
            'events' => $events->map(fn (stdClass $event): array => [
                'action' => $event->action,
                'actor_name' => $event->actor_name,
                'created_at' => CarbonImmutable::parse($event->created_at)->utc()->toIso8601String(),
                'previous_date' => $event->previous_date,
                'previous_start_time' => $event->previous_start_time === null ? null : $this->timeLabel($event->previous_start_time),
                'date' => $event->date,
                'start_time' => $this->timeLabel($event->start_time),
                'reason' => $event->reason,
            ])->all(),
        ];
    }

    public function createAppointment(int $organizationId, int $actorId, string $actorName, array $input): array
    {
        $id = strtolower($input['id']);
        $serviceId = strtolower($input['service_id']);
        $customerId = isset($input['customer_id']) ? strtolower($input['customer_id']) : null;
        $customer = isset($input['customer']) ? $this->customerValues($input['customer'], 'customer.') : null;
        $date = $input['date'];
        $start = $input['start_time'].':00';
        $note = $this->note($input['note'] ?? null);
        $hash = hash('sha256', json_encode([$customerId, $customer, $serviceId, $date, $start, $note], JSON_THROW_ON_ERROR));
        $query = $this->appointmentQuery($organizationId)->where('id', $id);
        $existing = (clone $query)->first();
        if ($existing) {
            return $this->replayedAppointment($organizationId, $actorId, $hash, $existing);
        }

        try {
            return DB::transaction(function () use ($query, $id, $organizationId, $actorId, $actorName, $customerId, $customer, $serviceId, $date, $start, $note, $hash): array {
                // Serialize booking writes for each customer. All existing-customer mutations take this lock first.
                $customerId = $customerId === null
                    ? $this->insertCustomer($organizationId, $actorId, $customer, 'customer.phone')
                    : $this->lockCustomer($organizationId, $customerId)->id;

                if ($existing = (clone $query)->lockForUpdate()->first()) {
                    return $this->replayedAppointment($organizationId, $actorId, $hash, $existing);
                }

                $service = $this->servicesQuery($organizationId)->where('id', $serviceId)->lockForUpdate()->first();
                if (! $service) {
                    throw ValidationException::withMessages(['service_id' => 'Dịch vụ không còn trong danh mục Spa.']);
                }

                $end = $this->endTime($start, (int) $service->duration_minutes);
                $this->checkAvailability($organizationId, $customerId, $date, $start, $end);
                DB::table('spa_appointments')->insert([
                    'id' => $id,
                    'organization_id' => $organizationId,
                    'customer_id' => $customerId,
                    'service_id' => $serviceId,
                    'service_name' => $service->name,
                    'service_price' => $service->price,
                    'duration_minutes' => $service->duration_minutes,
                    'requires_skin_photo' => $service->requires_skin_photo,
                    'date' => $date,
                    'start_time' => $start,
                    'end_time' => $end,
                    'status' => 'booked',
                    'note' => $note,
                    'request_hash' => $hash,
                    'version' => 1,
                    'created_by' => $actorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->recordEvent($id, 1, 'created', $actorId, $actorName, $date, $start);

                return $this->appointment($organizationId, $id);
            }, 3);
        } catch (ValidationException|UniqueConstraintViolationException $error) {
            // A simultaneous retry may have committed while our insert was waiting on a unique index.
            if ($existing = (clone $query)->first()) {
                return $this->replayedAppointment($organizationId, $actorId, $hash, $existing);
            }
            if ($error instanceof UniqueConstraintViolationException) {
                abort(409, 'SPA_APPOINTMENT_REQUEST_CONFLICT');
            }
            throw $error;
        }
    }

    public function reschedule(int $organizationId, int $actorId, string $actorName, string $id, array $input): array
    {
        return $this->changeAppointment($organizationId, $id, (int) $input['version'], function (Builder $query, stdClass $before) use ($organizationId, $actorId, $actorName, $id, $input): void {
            $date = $input['date'];
            $start = $input['start_time'].':00';
            if ($date === $before->date && $start === $before->start_time) {
                return;
            }

            $end = $this->endTime($start, (int) $before->duration_minutes);
            $this->checkAvailability($organizationId, $before->customer_id, $date, $start, $end, $id);
            $version = (int) $before->version + 1;
            $query->update([
                'date' => $date, 'start_time' => $start, 'end_time' => $end, 'version' => $version, 'updated_at' => now(),
            ]);
            $this->recordEvent($id, $version, 'rescheduled', $actorId, $actorName, $date, $start, $before);
        });
    }

    public function cancel(int $organizationId, int $actorId, string $actorName, string $id, array $input): array
    {
        $reason = trim($input['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Nhập lý do hủy lịch.']);
        }

        return $this->changeAppointment($organizationId, $id, (int) $input['version'], function (Builder $query, stdClass $before) use ($actorId, $actorName, $id, $reason): void {
            $version = (int) $before->version + 1;
            $query->update(['status' => 'cancelled', 'version' => $version, 'updated_at' => now()]);
            $this->recordEvent($id, $version, 'cancelled', $actorId, $actorName, $before->date, $before->start_time, $before, $reason);
        });
    }

    private function changeAppointment(int $organizationId, string $id, int $version, callable $change): array
    {
        $query = $this->appointmentQuery($organizationId)->where('id', $id);
        $customerId = (clone $query)->value('customer_id');
        abort_unless($customerId, 404);

        return DB::transaction(function () use ($query, $organizationId, $customerId, $id, $version, $change): array {
            $this->lockCustomer($organizationId, $customerId);
            $before = (clone $query)->lockForUpdate()->first();
            abort_unless($before, 404);
            abort_unless((int) $before->version === $version, 409, 'SPA_APPOINTMENT_STALE');
            abort_unless($before->status === 'booked', 409, 'SPA_APPOINTMENT_CANCELLED');
            $change($query, $before);

            return $this->appointment($organizationId, $id);
        }, 3);
    }

    private function replayedAppointment(int $organizationId, int $actorId, string $hash, stdClass $existing): array
    {
        abort_unless((int) $existing->created_by === $actorId && hash_equals($existing->request_hash, $hash), 409, 'SPA_APPOINTMENT_REQUEST_CONFLICT');

        return $this->appointment($organizationId, $existing->id);
    }

    private function checkAvailability(int $organizationId, string $customerId, string $date, string $start, string $end, ?string $ignoreId = null): void
    {
        $query = $this->appointmentQuery($organizationId)->where('customer_id', $customerId)
            ->where('date', $date)->where('status', 'booked')
            ->where('start_time', '<', $end)->where('end_time', '>', $start);
        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->lockForUpdate()->first(['id'])) {
            throw ValidationException::withMessages(['start_time' => 'Khách đã có lịch trong khoảng thời gian này.']);
        }
    }

    private function lockCustomer(int $organizationId, string $id): stdClass
    {
        $customer = $this->customerQuery($organizationId)->where('id', $id)->lockForUpdate()->first();
        abort_unless($customer, 404);

        return $customer;
    }

    private function insertCustomer(int $organizationId, int $actorId, array $values, string $field = 'phone'): string
    {
        $id = (string) Str::uuid();
        try {
            DB::table('spa_customers')->insert([
                'id' => $id, 'organization_id' => $organizationId,
                ...$values, 'version' => 1, 'created_by' => $actorId, 'updated_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->duplicatePhone($field);
        }

        return $id;
    }

    private function duplicatePhone(string $field): never
    {
        throw ValidationException::withMessages([$field => 'Số điện thoại này đã có trong danh sách khách.']);
    }

    private function customerValues(array $input, string $prefix = ''): array
    {
        $name = trim($input['name']);
        if ($name === '') {
            throw ValidationException::withMessages([$prefix.'name' => 'Nhập tên khách hàng.']);
        }

        return ['name' => $name, 'phone' => $this->validatedPhone($input['phone'], $prefix.'phone'), 'note' => $this->note($input['note'] ?? null)];
    }

    private function validatedPhone(string $value, string $field = 'phone'): string
    {
        $phone = $this->normalizePhone($value);
        if (! preg_match('/\A[0-9]{10,15}\z/', $phone)) {
            throw ValidationException::withMessages([$field => 'Nhập số điện thoại hợp lệ.']);
        }

        return $phone;
    }

    private function normalizePhone(string $value): string
    {
        $phone = preg_replace('/[\s().-]/u', '', $value) ?? '';
        if (str_starts_with($phone, '+84')) {
            return '0'.substr($phone, 3);
        }
        if (str_starts_with($phone, '84') && strlen($phone) === 11) {
            return '0'.substr($phone, 2);
        }

        return $phone;
    }

    private function endTime(string $start, int $duration): string
    {
        $end = 60 * (int) substr($start, 0, 2) + (int) substr($start, 3, 2) + $duration;
        if ($duration < 1 || $end >= 1440) {
            throw ValidationException::withMessages(['start_time' => 'Giờ kết thúc phải nằm trong cùng ngày hẹn.']);
        }

        return sprintf('%02d:%02d:00', intdiv($end, 60), $end % 60);
    }

    private function note(?string $value): ?string
    {
        $note = trim($value ?? '');

        return $note === '' ? null : $note;
    }

    private function recordEvent(string $id, int $version, string $action, int $actorId, string $actorName, string $date, string $start, ?stdClass $before = null, ?string $reason = null): void
    {
        DB::table('spa_appointment_events')->insert([
            'appointment_id' => $id, 'version' => $version, 'action' => $action,
            'actor_id' => $actorId, 'actor_name' => $actorName,
            'previous_date' => $before?->date, 'previous_start_time' => $before?->start_time,
            'date' => $date, 'start_time' => $start, 'reason' => $reason, 'created_at' => now(),
        ]);
    }

    private function customerQuery(int $organizationId): Builder
    {
        return DB::table('spa_customers')->where('organization_id', $organizationId);
    }

    private function servicesQuery(int $organizationId): Builder
    {
        abort_unless(config('spa.catalog_enabled'), 409, 'SPA_CATALOG_DISABLED');
        abort_unless(Schema::hasTable('spa_catalog_items'), 409, 'SPA_CATALOG_MIGRATION_REQUIRED');

        return DB::table('spa_catalog_items')->where('organization_id', $organizationId)
            ->where('kind', 'service')->whereNotNull('duration_minutes');
    }

    private function appointmentQuery(int $organizationId): Builder
    {
        return DB::table('spa_appointments')->where('spa_appointments.organization_id', $organizationId);
    }

    private function appointmentsWithCustomer(int $organizationId): Builder
    {
        return $this->appointmentQuery($organizationId)
            ->join('spa_customers', function ($join): void {
                $join->on('spa_customers.id', '=', 'spa_appointments.customer_id')
                    ->on('spa_customers.organization_id', '=', 'spa_appointments.organization_id');
            })->select('spa_appointments.*', 'spa_customers.name as customer_name');
    }

    private function presentCustomer(stdClass $row): array
    {
        return ['id' => $row->id, 'name' => $row->name, 'phone' => $row->phone, 'note' => $row->note ?? '', 'version' => (int) $row->version];
    }

    private function presentAppointment(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'customer' => ['id' => $row->customer_id, 'name' => $row->customer_name],
            'service' => [
                'id' => $row->service_id,
                'name' => $row->service_name,
                'price' => (int) $row->service_price,
                'duration_minutes' => (int) $row->duration_minutes,
                'requires_skin_photo' => (bool) $row->requires_skin_photo,
            ],
            'date' => $row->date,
            'start_time' => $this->timeLabel($row->start_time),
            'end_time' => $this->timeLabel($row->end_time),
            'status' => $row->status,
            'note' => $row->note ?? '',
            'version' => (int) $row->version,
        ];
    }

    private function timeLabel(string $value): string
    {
        return substr($value, 0, 5);
    }

    private function page(LengthAwarePaginator $page, callable $present): array
    {
        return [
            'data' => $page->getCollection()->map($present)->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ];
    }
}

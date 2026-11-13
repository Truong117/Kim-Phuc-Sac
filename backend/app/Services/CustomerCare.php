<?php

namespace App\Services;

use App\Models\DailyReportItem;
use App\Models\Task;
use App\Models\TaskOutput;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class CustomerCare
{
    public function ready(): bool
    {
        return Schema::hasTable('care_customers') && Schema::hasTable('care_report_entries') && Schema::hasTable('care_attachments');
    }

    public function visible(User $actor, int $organization): Builder
    {
        return DB::table('care_customers')->where('care_customers.organization_id', $organization)
            ->where(function ($q) use ($actor): void {
                $q->where('created_by', $actor->id)->orWhereIn('care_customers.id',
                    DB::table('task_outputs')->whereNotNull('care_customer_id')->whereIn('task_id',
                        app(TaskScopeService::class)->queryFor($actor)->select('tasks.id'))->select('care_customer_id'));
            });
    }

    /** Exact identifiers only. Display names are never customer identities. */
    public static function normalizeContact(?string $value, string $field): ?string
    {
        $value = trim($value ?? '');
        if ($value === '') {
            return null;
        }
        if (preg_match('/\A\+?[\d\s().-]+\z/D', $value)) {
            $phone = preg_replace('/[^\d+]/', '', $value);
            if (str_starts_with($phone, '0')) {
                $phone = '+84'.substr($phone, 1);
            } elseif (str_starts_with($phone, '84')) {
                $phone = '+'.$phone;
            }
            if (preg_match('/\A\+[1-9]\d{7,14}\z/D', $phone)) {
                return 'phone:'.$phone;
            }
        } else {
            $url = parse_url($value);
            if (filter_var($value, FILTER_VALIDATE_URL) && in_array(strtolower($url['scheme'] ?? ''), ['https', 'http'], true)
                && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['port'])
                && ! filter_var($url['host'] ?? '', FILTER_VALIDATE_IP) && str_contains($url['host'] ?? '', '.')) {
                $host = strtolower($url['host']);
                if (in_array($host, ['www.facebook.com', 'm.facebook.com', 'mbasic.facebook.com', 'fb.com', 'www.fb.com'], true)) {
                    $host = 'facebook.com';
                } else {
                    $host = preg_replace('/^www\./', '', $host);
                }
                $path = rtrim($url['path'] ?? '', '/');
                parse_str($url['query'] ?? '', $query);
                if ($host === 'facebook.com' && $path === '/profile.php' && isset($query['id']) && ctype_digit((string) $query['id'])) {
                    return 'profile:facebook.com/profile.php?id='.$query['id'];
                }
                if ($path !== '' && ! ($host === 'facebook.com' && $path === '/profile.php')) {
                    $query = array_filter($query, fn ($key) => ! preg_match('/^(utm_|fbclid$|mibextid$|ref$|refsrc$)/i', $key), ARRAY_FILTER_USE_KEY);
                    ksort($query);

                    return 'profile:'.$host.$path.($query ? '?'.http_build_query($query) : '');
                }
            }
        }
        throw ValidationException::withMessages([$field => 'Nhập số điện thoại hợp lệ (ví dụ 0901234567) hoặc liên kết hồ sơ http/https, không chứa thông tin đăng nhập.']);
    }

    /** Runs inside the report transaction, after task/revision/period authorization. */
    public function prepare(User $actor, Task $task, array &$input, string $field): string
    {
        abort_unless($this->ready(), 409, 'CUSTOMER_CARE_MIGRATION_REQUIRED');
        if (($input['unit'] ?? null) !== 'khách hàng' || ($task->quantity_unit && $task->quantity_unit !== 'khách hàng')) {
            throw ValidationException::withMessages([$field => 'Form khách hàng chỉ dành cho đơn vị khách hàng; không áp dụng cho video hoặc sản phẩm media.']);
        }
        // Serialize same employee's quick creates across tasks and attachment requests.
        DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
        $outputs = [];
        $entries = [];
        $seen = [];
        foreach ($input['care_entries'] as $i => $entry) {
            $key = "$field.care_entries.$i";
            if (! empty($entry['customer_id']) && ! empty($entry['new_customer'])) {
                throw ValidationException::withMessages([$key => 'Chọn khách đã có hoặc thêm khách mới, không dùng cả hai.']);
            }
            $customer = ! empty($entry['customer_id'])
                ? $this->visible($actor, $task->organization_id)->where('care_customers.id', $entry['customer_id'])->first()
                : $this->create($actor, $task->organization_id, $entry['new_customer'] ?? [], $key);
            if (! $customer) {
                throw ValidationException::withMessages([$key => 'Khách hàng không thuộc phạm vi bạn được sử dụng. Hãy tìm và chọn lại.']);
            }
            if (isset($seen[$customer->id])) {
                throw ValidationException::withMessages([$key => 'Khách được chọn trùng. Gộp các việc hỗ trợ vào cùng một dòng khách.']);
            }
            $seen[$customer->id] = true;
            if (! empty($entry['contact_update'])) {
                $this->addContact($task->organization_id, $customer->id, $entry['contact_update']['channel'], $entry['contact_update']['value'], $key);
            }
            $attachments = array_values(array_unique($entry['attachment_ids'] ?? []));
            foreach ($attachments as $id) {
                $file = DB::table('care_attachments')->where('id', $id)->where('task_id', $task->id)->where('created_by', $actor->id)->first();
                if (! $file || (! $file->linked_at && $file->created_at < now()->subDay()->toDateTimeString())) {
                    throw ValidationException::withMessages([$key => 'Tệp minh chứng không hợp lệ hoặc đã hết hạn. Hãy đính kèm lại.']);
                }
            }
            $activities = array_map(fn ($activity) => ['service' => trim($activity['service']), 'result' => trim($activity['result'])], $entry['activities']);
            if (collect($activities)->contains(fn ($a) => $a['service'] === '' || $a['result'] === '')) {
                throw ValidationException::withMessages([$key => 'Nhập nội dung hỗ trợ và kết quả cho từng khách.']);
            }
            foreach ($entry['evidence_links'] ?? [] as $link) {
                $parts = parse_url($link);
                if (isset($parts['user']) || isset($parts['pass'])) {
                    throw ValidationException::withMessages([$key => 'Minh chứng không được chứa thông tin đăng nhập.']);
                }
            }
            $existing = TaskOutput::query()->where('task_id', $task->id)->where('unit', 'khách hàng')->where('care_customer_id', $customer->id)->first();
            // Explicitly bind an old manually coded record; never guess by customer name.
            if (! empty($entry['legacy_output_id'])) {
                $legacy = TaskOutput::query()->where('task_id', $task->id)->where('unit', 'khách hàng')->find($entry['legacy_output_id']);
                if (! $legacy || ($legacy->care_customer_id && (int) $legacy->care_customer_id !== (int) $customer->id)
                    || ($existing && $existing->id !== $legacy->id)) {
                    throw ValidationException::withMessages([$key => 'Không thể gắn khách vào mã đối soát cũ này.']);
                }
                $existing = $legacy;
                $existing->care_customer_id = $customer->id;
                $existing->save();
            }
            if (! $existing && TaskOutput::query()->where('task_id', $task->id)->where('unit', 'khách hàng')->where('reference', 'KH-'.$customer->id)->exists()) {
                throw ValidationException::withMessages([$key => 'Mã khách đã có trong dữ liệu đối soát cũ. Hãy gắn rõ dòng đối soát tương ứng.']);
            }
            $outputs[] = ['id' => $existing?->id, 'name' => $customer->name, 'reference' => $existing?->reference ?? 'KH-'.$customer->id,
                'care_customer_id' => (int) $customer->id, 'completed' => true];
            $entries[] = ['client_key' => $entry['client_key'], 'customer_id' => (int) $customer->id, 'name' => $customer->name,
                'activities' => $activities, 'evidence_links' => array_values(array_unique($entry['evidence_links'] ?? [])), 'attachment_ids' => $attachments];
        }
        $input['care_entries'] = $entries;
        $input['outputs'] = $outputs;

        return self::text($entries);
    }

    public static function text(array $entries): string
    {
        return implode("\n", array_map(fn ($entry) => $entry['name'].' — '.implode('; ', array_map(fn ($a) => $a['service'].': '.$a['result'], $entry['activities'])), $entries));
    }

    private function create(User $actor, int $organization, array $input, string $field): object
    {
        if (empty($input['request_key']) || trim($input['name'] ?? '') === '') {
            throw ValidationException::withMessages([$field => 'Chọn khách hoặc nhập tên để tạo khách mới.']);
        }
        $identity = self::normalizeContact($input['contact'] ?? '', "$field.new_customer.contact");
        $existing = DB::table('care_customers')->where('organization_id', $organization)->where('created_by', $actor->id)->where('request_key', $input['request_key'])->first();
        if ($existing) {
            return $existing;
        }
        if ($identity && DB::table('care_customer_contacts')->where('organization_id', $organization)->where('identity_hash', hash('sha256', $identity))->exists()) {
            throw ValidationException::withMessages([$field => 'Số điện thoại hoặc hồ sơ này đã được lưu. Hãy tìm và chọn khách đã có; nếu không thấy, liên hệ quản lý để được phân quyền.']);
        }
        $id = DB::table('care_customers')->insertGetId(['organization_id' => $organization, 'created_by' => $actor->id,
            'request_key' => $input['request_key'], 'name' => trim($input['name']), 'created_at' => now(), 'updated_at' => now()]);
        $this->addContact($organization, $id, $input['channel'], $input['contact'] ?? '', $field);

        return DB::table('care_customers')->find($id);
    }

    private function addContact(int $organization, int $customer, string $channel, string $value, string $field): void
    {
        $identity = self::normalizeContact($value, $field);
        if ($identity) {
            $existing = DB::table('care_customer_contacts')->where('organization_id', $organization)->where('identity_hash', hash('sha256', $identity))->first();
            if ($existing) {
                if ((int) $existing->customer_id !== $customer) {
                    throw ValidationException::withMessages([$field => 'Thông tin liên hệ thuộc khách khác. Hãy chọn khách đã có hoặc nhờ quản lý đối chiếu.']);
                }

                return;
            }
        }
        try {
            DB::table('care_customer_contacts')->insert(['organization_id' => $organization, 'customer_id' => $customer,
                'channel' => trim($channel), 'value' => trim($value) ?: null, 'identity_hash' => $identity ? hash('sha256', $identity) : null]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([$field => 'Thông tin liên hệ vừa được lưu ở hồ sơ khác. Tải lại danh sách khách trước khi lưu.']);
        }
    }

    public function saveEntries(DailyReportItem $row, array $input): void
    {
        if (! array_key_exists('care_entries', $input)) {
            return;
        }
        DB::table('care_report_entries')->where('report_item_id', $row->id)->delete();
        foreach ($input['care_entries'] as $entry) {
            DB::table('care_report_entries')->insert(['task_id' => $row->task_id, 'report_item_id' => $row->id, 'customer_id' => $entry['customer_id'],
                'activities' => json_encode($entry['activities'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'evidence_links' => json_encode($entry['evidence_links'], JSON_THROW_ON_ERROR), 'attachment_ids' => json_encode($entry['attachment_ids'], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('care_attachments')->whereIn('id', $entry['attachment_ids'])->update(['linked_at' => now()]);
        }
    }
}

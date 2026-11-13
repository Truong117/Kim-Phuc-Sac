<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class CareServiceCatalog
{
    public const DEFAULTS = ['Hướng dẫn chăm sóc da tại nhà', 'Theo dõi sau điều trị', 'Tư vấn sản phẩm', 'Tiếp nhận phản hồi', 'Nhắc lịch tái khám'];

    public static function key(array $value): string
    {
        return ! empty($value['id']) ? 'id:'.$value['id'] : 'name:'.NamedWorkOutputs::identity($value['name']);
    }

    public function options(Task $task, string $search): array
    {
        abort_unless(Schema::hasTable('care_services'), 409, 'CARE_SERVICE_MIGRATION_REQUIRED');
        $query = DB::table('care_services')->where('organization_id', $task->organization_id);
        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }
        $page = $query->orderBy('name')->orderBy('id')->paginate(20);
        $defaults = [];
        if ($page->currentPage() === 1) {
            $known = DB::table('care_services')->where('organization_id', $task->organization_id)
                ->whereIn('identity_hash', array_map(NamedWorkOutputs::identity(...), self::DEFAULTS))->pluck('identity_hash')->all();
            foreach (self::DEFAULTS as $name) {
                if (! in_array(NamedWorkOutputs::identity($name), $known, true) && ($search === '' || mb_stripos($name, $search) !== false)) {
                    $defaults[] = ['id' => null, 'name' => $name];
                }
            }
        }

        return ['data' => [...$page->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name])->all(), ...$defaults],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]];
    }

    /** Selection and inline creation commit atomically with the daily report. */
    public function resolve(User $actor, Task $task, array $input, string $field): array
    {
        abort_unless(Schema::hasTable('care_services'), 409, 'CARE_SERVICE_MIGRATION_REQUIRED');
        $result = [];
        foreach ($input as $value) {
            if (! empty($value['id'])) {
                $row = DB::table('care_services')->where('organization_id', $task->organization_id)->find($value['id']);
                if (! $row) {
                    throw ValidationException::withMessages([$field => 'Nội dung hỗ trợ không thuộc tổ chức hoặc không còn tồn tại.']);
                }
            } else {
                $name = trim($value['name']);
                if ($name === '') {
                    throw ValidationException::withMessages([$field => 'Nhập tên nội dung hỗ trợ.']);
                }
                $hash = NamedWorkOutputs::identity($name);
                $row = DB::table('care_services')->where('organization_id', $task->organization_id)->where('identity_hash', $hash)->first();
                if (! $row) {
                    // The organization/name unique index arbitrates simultaneous employee creates.
                    DB::table('care_services')->insertOrIgnore(['organization_id' => $task->organization_id, 'created_by' => $actor->id,
                        'name' => $name, 'identity_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
                    $row = DB::table('care_services')->where('organization_id', $task->organization_id)->where('identity_hash', $hash)->first();
                }
            }
            if (isset($result[$row->id])) {
                throw ValidationException::withMessages([$field => 'Một nội dung chỉ được chọn một lần trong cùng đầu việc.']);
            }
            $result[$row->id] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return array_values($result);
    }
}

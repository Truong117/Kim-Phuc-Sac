<?php

namespace App\Http\Controllers\Spa;

use App\Enums\DataScope;
use App\Http\Controllers\Controller;
use App\Services\AuthorizationService;
use App\Services\KpsMembershipResolver;
use App\Services\NamedWorkOutputs;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SpaCatalogController extends Controller
{
    public function status(Request $request, AuthorizationService $authorization): JsonResponse
    {
        return response()->json([
            'enabled' => (bool) config('spa.catalog_enabled'),
            'can_manage' => in_array($authorization->scopeFor($request->user(), 'spa.services.manage'), [DataScope::ORGANIZATION, DataScope::ALL], true),
        ])->header('Cache-Control', 'private, no-store');
    }

    private function organization(Request $request): int
    {
        abort_unless(config('spa.catalog_enabled'), 409, 'SPA_CATALOG_DISABLED');
        abort_unless(Schema::hasTable('spa_catalog_items'), 409, 'SPA_CATALOG_MIGRATION_REQUIRED');

        return (int) app(KpsMembershipResolver::class)->activeMembershipFor($request->user())->organization_id;
    }

    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $input = $request->validate([
            'kind' => ['required', Rule::in(['service', 'product'])],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $query = DB::table('spa_catalog_items')->where('organization_id', $organization)->where('kind', $input['kind']);
        if (filled($input['search'] ?? null)) {
            $query->where('name', 'like', '%'.addcslashes(trim($input['search']), '%_\\').'%');
        }
        $page = $query->orderBy('name')->orderBy('id')->paginate(20);

        return response()->json([
            'data' => $page->map($this->present(...))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $input = $this->input($request, creating: true);
        $values = $this->values($input);
        $row = $this->write(function () use ($request, $organization, $input, $values) {
            // A stable client UUID makes a retry after a lost response safe.
            $existing = DB::table('spa_catalog_items')->where('id', $input['id'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->organization_id === $organization, 404);
                abort_unless((int) $existing->created_by === (int) $request->user()->id
                    && (int) $existing->version === 1
                    && collect($values)->every(fn ($value, $key) => $existing->{$key} == $value), 409, 'SPA_CATALOG_CONFLICT');

                return $existing;
            }
            $this->uniqueName($organization, $values);
            DB::table('spa_catalog_items')->insert([
                ...$values, 'id' => $input['id'], 'organization_id' => $organization,
                'created_by' => $request->user()->id, 'updated_by' => $request->user()->id,
                'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('spa_catalog_items')->where('organization_id', $organization)->find($input['id']);
        });

        return response()->json(['data' => $this->present($row)], 201)->header('Cache-Control', 'private, no-store');
    }

    public function update(string $id, Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $input = $this->input($request, creating: false);
        $values = $this->values($input);
        $row = $this->write(function () use ($id, $request, $organization, $input, $values) {
            $query = DB::table('spa_catalog_items')->where('organization_id', $organization)->where('id', $id);
            $existing = (clone $query)->lockForUpdate()->first();
            abort_unless($existing, 404);
            abort_unless($existing->kind === $input['kind'] && (int) $existing->version === (int) $input['version'], 409, 'SPA_CATALOG_CONFLICT');
            $this->uniqueName($organization, $values, $id);
            $query->update([...$values, 'version' => $existing->version + 1, 'updated_by' => $request->user()->id, 'updated_at' => now()]);

            return $query->first();
        });

        return response()->json(['data' => $this->present($row)])->header('Cache-Control', 'private, no-store');
    }

    private function input(Request $request, bool $creating): array
    {
        return $request->validate([
            'id' => $creating ? ['required', 'uuid'] : ['prohibited'],
            'version' => $creating ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'kind' => ['required', Rule::in(['service', 'product'])],
            'name' => ['required', 'string', 'max:100'],
            'price' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'unit' => ['required_if:kind,product', 'prohibited_if:kind,service', 'string', 'max:30'],
            'duration_minutes' => ['required_if:kind,service', 'prohibited_if:kind,product', 'integer', 'min:15', 'max:180', 'multiple_of:15'],
            'group' => ['nullable', 'prohibited_if:kind,product', 'string', 'max:100'],
            'description' => ['nullable', 'prohibited_if:kind,product', 'string', 'max:1000'],
            'requires_skin_photo' => ['required_if:kind,service', 'prohibited_if:kind,product', 'boolean'],
            'organization_id' => ['prohibited'], 'stock' => ['prohibited'], 'created_by' => ['prohibited'], 'updated_by' => ['prohibited'],
        ]);
    }

    private function values(array $input): array
    {
        $name = trim($input['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Nhập tên dịch vụ hoặc sản phẩm.']);
        }

        return [
            'kind' => $input['kind'], 'name' => $name, 'identity_hash' => NamedWorkOutputs::identity($name), 'price' => $input['price'],
            'unit' => $input['kind'] === 'product' ? trim($input['unit']) : null,
            'duration_minutes' => $input['kind'] === 'service' ? $input['duration_minutes'] : null,
            'group' => $input['kind'] === 'service' ? trim($input['group'] ?? '') : null,
            'description' => $input['kind'] === 'service' ? trim($input['description'] ?? '') : null,
            'requires_skin_photo' => $input['kind'] === 'service' && $input['requires_skin_photo'],
        ];
    }

    private function uniqueName(int $organization, array $values, ?string $except = null): void
    {
        $query = DB::table('spa_catalog_items')->where('organization_id', $organization)->where('kind', $values['kind'])->where('identity_hash', $values['identity_hash']);
        if ($except) {
            $query->where('id', '!=', $except);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['name' => 'Tên này đã có trong danh mục.']);
        }
    }

    private function write(callable $callback): object
    {
        try {
            return DB::transaction($callback);
        } catch (QueryException $error) {
            if (($error->errorInfo[1] ?? null) === 1062 || str_contains($error->getMessage(), 'UNIQUE constraint failed')) {
                throw ValidationException::withMessages(['name' => 'Tên hoặc yêu cầu tạo đã tồn tại. Hãy tải lại danh mục trước khi thử lại.']);
            }

            throw $error;
        }
    }

    private function present(object $row): array
    {
        return [
            'id' => $row->id, 'kind' => $row->kind, 'name' => $row->name, 'price' => (int) $row->price,
            'unit' => $row->unit, 'duration_minutes' => $row->duration_minutes === null ? null : (int) $row->duration_minutes,
            'group' => $row->group, 'description' => $row->description, 'requires_skin_photo' => (bool) $row->requires_skin_photo,
            'version' => (int) $row->version,
        ];
    }
}

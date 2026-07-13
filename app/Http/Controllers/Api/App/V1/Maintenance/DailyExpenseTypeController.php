<?php

namespace App\Http\Controllers\Api\App\V1\Maintenance;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\Maintenance\DailyExpenseTypeIndexRequest;
use App\Http\Requests\Api\App\V1\Maintenance\StoreDailyExpenseTypeRequest;
use App\Http\Requests\Api\App\V1\Maintenance\UpdateDailyExpenseTypeRequest;
use App\Http\Resources\App\V1\Maintenance\DailyExpenseTypeResource;
use App\Models\Tenant\DailyExpenseType;
use App\Models\Tenancy\Tenant;
use App\Services\V1\SubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DailyExpenseTypeController extends Controller
{
    use InteractsWithTenantModels;

    public function __construct(private SubscriptionService $subscriptionService)
    {
    }

    public function index(DailyExpenseTypeIndexRequest $request)
    {
        $this->authorize('viewAny', DailyExpenseType::class);

        $filters = $request->validated();
        $query = DailyExpenseType::query()->withCount('dailyExpenses');

        if (!empty($filters['search'] ?? null)) {
            $this->applyPrefixSearch($query, $filters['search'], ['name']);
        }

        if (!empty($filters['name'] ?? null)) {
            $query->where('name', 'like', $filters['name'].'%');
        }

        $this->applySort($query, $filters['sort'] ?? null, ['name', 'created_at'], 'name', 'asc');
        $types = $query->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::resource(DailyExpenseTypeResource::collection($types), 'Daily expense types list');
    }

    public function store(StoreDailyExpenseTypeRequest $request)
    {
        $this->authorize('create', DailyExpenseType::class);
        $this->assertWorkspaceAllowsMutation();

        $name = $this->normalizeName($request->validated('name'));

        $exists = DailyExpenseType::query()->where('name', $name)->exists();
        if ($exists) {
            return ApiResponse::error('Daily expense type already exists', ['name' => ['Duplicate daily expense type name']], 422);
        }

        $type = DB::transaction(fn () => DailyExpenseType::query()->create([
            'name' => $name,
        ]));

        return ApiResponse::resource(new DailyExpenseTypeResource($type), 'Daily expense type created', 201);
    }

    public function show(DailyExpenseType $dailyExpenseType)
    {
        $this->authorize('view', $dailyExpenseType);

        return ApiResponse::resource(
            new DailyExpenseTypeResource($dailyExpenseType->loadCount('dailyExpenses')),
            'Daily expense type details'
        );
    }

    public function update(UpdateDailyExpenseTypeRequest $request, DailyExpenseType $dailyExpenseType)
    {
        $this->authorize('update', $dailyExpenseType);
        $this->assertWorkspaceAllowsMutation();

        $data = $request->validated();
        $name = array_key_exists('name', $data)
            ? $this->normalizeName($data['name'])
            : $dailyExpenseType->name;

        $exists = DailyExpenseType::query()
            ->where('name', $name)
            ->whereKeyNot($dailyExpenseType->id)
            ->exists();

        if ($exists) {
            return ApiResponse::error('Daily expense type already exists', ['name' => ['Duplicate daily expense type name']], 422);
        }

        DB::transaction(function () use ($dailyExpenseType, $name) {
            $dailyExpenseType->fill(['name' => $name])->save();

            $dailyExpenseType->dailyExpenses()->update(['title' => $name]);
        });

        return ApiResponse::resource(
            new DailyExpenseTypeResource($dailyExpenseType->fresh()->loadCount('dailyExpenses')),
            'Daily expense type updated'
        );
    }

    public function destroy(DailyExpenseType $dailyExpenseType)
    {
        $this->authorize('delete', $dailyExpenseType);
        $this->assertWorkspaceAllowsMutation();

        if ($dailyExpenseType->dailyExpenses()->exists()) {
            return ApiResponse::error(
                'Daily expense type cannot be deleted',
                ['daily_expense_type' => ['This daily expense type is already used by expense records. Update those records first or rename the type instead.']],
                422
            );
        }

        DB::transaction(fn () => $dailyExpenseType->delete());

        return ApiResponse::success('Daily expense type deleted');
    }

    private function normalizeName(string $value): string
    {
        return Str::of($value)->trim()->squish()->ucfirst()->toString();
    }

    private function assertWorkspaceAllowsMutation(): void
    {
        $tenant = request()->attributes->get('tenant');

        if ($tenant instanceof Tenant) {
            $this->subscriptionService->assertWorkspaceAllowsPropertyScopedMutation($tenant);
        }
    }
}

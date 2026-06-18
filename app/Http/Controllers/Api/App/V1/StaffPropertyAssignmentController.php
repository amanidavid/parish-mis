<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\StaffPropertyAssignmentIndexRequest;
use App\Http\Requests\Api\App\V1\StoreStaffPropertyAssignmentRequest;
use App\Http\Requests\Api\App\V1\UpdateStaffPropertyAssignmentRequest;
use App\Http\Resources\App\V1\StaffPropertyAssignmentResource;
use App\Models\Tenant\Property;
use App\Models\Tenant\StaffPropertyAssignment;
use App\Models\Tenant\User;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;

class StaffPropertyAssignmentController extends Controller
{
    use InteractsWithTenantModels;

    /**
     * Handle the index request.
     */
    public function index(StaffPropertyAssignmentIndexRequest $request)
    {
        $this->authorize('viewAny', StaffPropertyAssignment::class);

        $filters = $request->validated();
        $query = StaffPropertyAssignment::query()->with(['user', 'property']);

        if (!empty($filters['property_uuid'] ?? null)) {
            $property = $this->resolveModelByUuid(Property::class, $filters['property_uuid']);
            if (!$property) {
                return ApiResponse::error('Property not found', ['property_uuid' => ['Invalid property identifier']], 422);
            }

            $query->where('property_id', $property->id);
        }

        if (!empty($filters['user_uuid'] ?? null)) {
            $user = $this->resolveModelByUuid(User::class, $filters['user_uuid']);
            if (!$user) {
                return ApiResponse::error('Staff user not found', ['user_uuid' => ['Invalid staff identifier']], 422);
            }

            $query->where('user_id', $user->id);
        }

        if (!empty($filters['search'] ?? null)) {
            $query->where(function ($innerQuery) use ($filters) {
                $innerQuery
                    ->whereHas('user', fn ($userQuery) => $userQuery->where('name', 'like', $filters['search'].'%'))
                    ->orWhereHas('property', fn ($propertyQuery) => $propertyQuery->where('name', 'like', $filters['search'].'%'));
            });
        }

        $this->applySort($query, $filters['sort'] ?? null, ['created_at'], 'created_at', 'desc');
        $assignments = $query->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::resource(StaffPropertyAssignmentResource::collection($assignments), 'Staff property assignments list');
    }

    /**
     * Handle the store request.
     */
    public function store(StoreStaffPropertyAssignmentRequest $request)
    {
        $this->authorize('create', StaffPropertyAssignment::class);

        $data = $request->validated();
        $user = $this->resolveModelByUuid(User::class, $data['user_uuid']);
        $property = $this->resolveModelByUuid(Property::class, $data['property_uuid']);

        if (!$user) {
            return ApiResponse::error('Staff user not found', ['user_uuid' => ['Invalid staff identifier']], 422);
        }

        if (!$property) {
            return ApiResponse::error('Property not found', ['property_uuid' => ['Invalid property identifier']], 422);
        }

        $exists = StaffPropertyAssignment::query()
            ->where('user_id', $user->id)
            ->where('property_id', $property->id)
            ->exists();

        if ($exists) {
            return ApiResponse::error(
                'Staff assignment already exists',
                ['assignment' => ['This user is already assigned to the selected property']],
                422
            );
        }

        $assignment = DB::transaction(fn () => StaffPropertyAssignment::query()->create([
            'user_id' => $user->id,
            'property_id' => $property->id,
        ]));

        return ApiResponse::resource(
            new StaffPropertyAssignmentResource($assignment->load(['user', 'property'])),
            'Staff property assignment created',
            201
        );
    }

    /**
     * Handle the show request.
     */
    public function show(StaffPropertyAssignment $staffPropertyAssignment)
    {
        $this->authorize('view', $staffPropertyAssignment);

        return ApiResponse::resource(
            new StaffPropertyAssignmentResource($staffPropertyAssignment->load(['user', 'property'])),
            'Staff property assignment details'
        );
    }

    /**
     * Handle the update request.
     */
    public function update(UpdateStaffPropertyAssignmentRequest $request, StaffPropertyAssignment $staffPropertyAssignment)
    {
        $this->authorize('update', $staffPropertyAssignment);

        $data = $request->validated();
        $user = !empty($data['user_uuid'] ?? null)
            ? $this->resolveModelByUuid(User::class, $data['user_uuid'])
            : $staffPropertyAssignment->user;
        $property = !empty($data['property_uuid'] ?? null)
            ? $this->resolveModelByUuid(Property::class, $data['property_uuid'])
            : $staffPropertyAssignment->property;

        if (!$user) {
            return ApiResponse::error('Staff user not found', ['user_uuid' => ['Invalid staff identifier']], 422);
        }

        if (!$property) {
            return ApiResponse::error('Property not found', ['property_uuid' => ['Invalid property identifier']], 422);
        }

        $exists = StaffPropertyAssignment::query()
            ->where('user_id', $user->id)
            ->where('property_id', $property->id)
            ->whereKeyNot($staffPropertyAssignment->id)
            ->exists();

        if ($exists) {
            return ApiResponse::error(
                'Staff assignment already exists',
                ['assignment' => ['This user is already assigned to the selected property']],
                422
            );
        }

        DB::transaction(function () use ($staffPropertyAssignment, $user, $property) {
            $staffPropertyAssignment->fill([
                'user_id' => $user->id,
                'property_id' => $property->id,
            ])->save();
        });

        return ApiResponse::resource(
            new StaffPropertyAssignmentResource($staffPropertyAssignment->fresh()->load(['user', 'property'])),
            'Staff property assignment updated'
        );
    }

    /**
     * Handle the destroy request.
     */
    public function destroy(StaffPropertyAssignment $staffPropertyAssignment)
    {
        $this->authorize('delete', $staffPropertyAssignment);

        DB::transaction(fn () => $staffPropertyAssignment->delete());

        return ApiResponse::success('Staff property assignment deleted');
    }
}

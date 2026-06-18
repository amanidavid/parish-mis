<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Api\App\V1\Concerns\InteractsWithTenantModels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\StoreTenantUserRequest;
use App\Http\Requests\Api\App\V1\TenantUserIndexRequest;
use App\Http\Requests\Api\App\V1\UpdateTenantUserRequest;
use App\Http\Resources\App\V1\TenantUserResource;
use App\Models\Landlord\BaseUser;
use App\Models\Landlord\UserTenant;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Services\V1\StaffProvisioningService;
use App\Support\ApiMessages;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TenantUserController extends Controller
{
    use InteractsWithTenantModels;

    /**
     * Create a new instance.
     */
    public function __construct(private StaffProvisioningService $staffProvisioningService)
    {
    }

    /**
     * Handle the index request.
     */
    public function index(TenantUserIndexRequest $request)
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validated();
        $query = User::query()->with([
            'baseUser:id,uuid,username,email,phone,status',
            'roles',
        ]);

        if (!empty($filters['name'] ?? null)) {
            $query->where('name', 'like', $filters['name'].'%');
        }

        if (!empty($filters['phone'] ?? null)) {
            $query->where('phone', 'like', $filters['phone'].'%');
        }

        if (!empty($filters['search'] ?? null)) {
            $this->applyPrefixSearch($query, $filters['search'], ['name', 'email', 'phone']);
        }

        if (!empty($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['role'] ?? null)) {
            $query->role($filters['role']);
        }

        $this->applySort($query, $filters['sort'] ?? null, ['name', 'created_at'], 'name', 'asc');
        $tenantUsers = $query->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::resource(TenantUserResource::collection($tenantUsers), 'Tenant staff list');
    }

    /**
     * Handle the store request.
     */
    public function store(StoreTenantUserRequest $request)
    {
        $this->authorize('create', User::class);

        $tenant = request()->attributes->get('tenant');
        if (!$tenant) {
            return ApiResponse::serverError(
                ['tenant' => [ApiMessages::TENANT_CONTEXT_UNAVAILABLE]],
                ApiMessages::TENANT_CONTEXT_UNAVAILABLE
            );
        }

        try {
            $result = $this->staffProvisioningService->createTenantStaff($tenant, $request->validated());
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error(
                'Staff account could not be created.',
                $this->mapStaffProvisioningError($e->getMessage()),
                422
            );
        } catch (\Throwable $e) {
            return ApiResponse::serverError(
                ['staff' => ['The staff account could not be created at this time.']],
                'Staff account could not be created.'
            );
        }

        return ApiResponse::success('Tenant staff created', [
            'user' => (new TenantUserResource($result['tenant_user']->load([
                'baseUser',
                'roles.permissions',
                'permissions' => fn ($permissionQuery) => $permissionQuery->orderBy('module')->orderBy('name'),
            ])))->resolve(),
            'credential_delivery' => [
                'channel' => $result['delivery_channel'],
                'password_generated' => $result['password_generated'],
            ],
        ], 201);
    }

    /**
     * Handle the show request.
     */
    public function show(User $tenantUser)
    {
        $this->authorize('view', $tenantUser);

        $tenantUser->loadMissing([
            'baseUser',
            'roles.permissions',
            'permissions' => fn ($permissionQuery) => $permissionQuery->orderBy('module')->orderBy('name'),
        ]);

        return ApiResponse::resource(new TenantUserResource($tenantUser), 'Tenant staff details');
    }

    /**
     * Handle the update request.
     */
    public function update(UpdateTenantUserRequest $request, User $tenantUser)
    {
        $this->authorize('update', $tenantUser);

        if ($tenantUser->hasRole('owner')) {
            return ApiResponse::error('Tenant staff update failed', ['staff' => ['Owner account cannot be updated through the staff endpoint']], 422);
        }

        $tenantUser->loadMissing('baseUser');

        $data = $request->validated();
        $baseUser = $tenantUser->baseUser;
        $newPhone = $data['phone'] ?? $tenantUser->phone;
        $newEmail = array_key_exists('email', $data) ? $data['email'] : $tenantUser->email;
        $newUsername = isset($data['username']) ? trim((string) $data['username']) : null;

        $tenantConflict = User::query()
            ->where(function ($query) use ($newPhone, $newEmail) {
                $query->where('phone', $newPhone);
                if (!empty($newEmail)) {
                    $query->orWhere('email', $newEmail);
                }
            })
            ->whereKeyNot($tenantUser->id)
            ->exists();

        if ($tenantConflict) {
            return ApiResponse::error('Tenant staff update failed', ['staff' => ['Phone or email already belongs to another staff account in this workspace']], 422);
        }

        if ($baseUser && $newUsername !== null && $newUsername !== '' && BaseUser::query()
            ->where('username', $newUsername)
            ->whereKeyNot($baseUser->id)
            ->exists()) {
            return ApiResponse::error('Staff account could not be updated.', [
                'username' => ['The selected username is already in use.'],
            ], 422);
        }

        try {
            if ($baseUser) {
                DB::connection('base')->transaction(function () use ($baseUser, $data, $newUsername, $newPhone, $newEmail) {
                    $baseUser->fill([
                        'username' => $newUsername !== null && $newUsername !== '' ? $newUsername : $baseUser->username,
                        'name' => $data['name'] ?? $baseUser->name,
                        'phone' => $newPhone,
                        'email' => $newEmail,
                        'status' => $data['status'] ?? $baseUser->status,
                    ]);

                    if (!empty($data['password'] ?? null)) {
                        $baseUser->password = Hash::make($data['password']);
                    }

                    $baseUser->save();
                });
            }

            DB::transaction(function () use ($tenantUser, $data, $newPhone, $newEmail) {
                $tenantUser->fill([
                    'name' => $data['name'] ?? $tenantUser->name,
                    'phone' => $newPhone,
                    'email' => $newEmail,
                    'status' => $data['status'] ?? $tenantUser->status,
                ])->save();

                if (array_key_exists('roles', $data)) {
                    $roles = collect($data['roles'])
                        ->filter(fn ($roleName) => is_string($roleName) && $roleName !== '')
                        ->map(fn ($roleName) => trim($roleName))
                        ->unique()
                        ->values()
                        ->all();

                    if (in_array('owner', $roles, true)) {
                        throw new \InvalidArgumentException('Owner role cannot be assigned through the staff endpoint.');
                    }

                    $count = Role::query()->where('guard_name', 'api')->whereIn('name', $roles)->count();
                    if ($count !== count($roles)) {
                        throw new \InvalidArgumentException('One or more tenant roles are invalid.');
                    }

                    $tenantUser->syncRoles($roles);
                }
            });
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error(
                'Staff account could not be updated.',
                $this->mapStaffProvisioningError($e->getMessage()),
                422
            );
        } catch (\Throwable $e) {
            return ApiResponse::serverError(
                ['staff' => ['The staff account could not be updated at this time.']],
                'Staff account could not be updated.'
            );
        }

        return ApiResponse::resource(new TenantUserResource($tenantUser->fresh()->load([
            'baseUser',
            'roles.permissions',
            'permissions' => fn ($permissionQuery) => $permissionQuery->orderBy('module')->orderBy('name'),
        ])), 'Tenant staff updated');
    }

    /**
     * Handle the destroy request.
     */
    public function destroy(User $tenantUser)
    {
        $this->authorize('delete', $tenantUser);

        $tenant = request()->attributes->get('tenant');
        if (!$tenant) {
            return ApiResponse::serverError(
                ['tenant' => [ApiMessages::TENANT_CONTEXT_UNAVAILABLE]],
                ApiMessages::TENANT_CONTEXT_UNAVAILABLE
            );
        }

        if ($tenantUser->hasRole('owner')) {
            return ApiResponse::error('Tenant staff deletion failed', ['staff' => ['Owner account cannot be deleted through the staff endpoint']], 422);
        }

        $currentTenantUser = request()->attributes->get('tenant_user');
        if ($currentTenantUser instanceof User && $currentTenantUser->id === $tenantUser->id) {
            return ApiResponse::error('Tenant staff deletion failed', ['staff' => ['You cannot delete your own active workspace account']], 422);
        }

        $baseUserId = $tenantUser->base_user_id;

        DB::transaction(fn () => $tenantUser->delete());

        DB::connection('base')->transaction(function () use ($baseUserId, $tenant) {
            UserTenant::query()
                ->where('user_id', $baseUserId)
                ->where('tenant_id', $tenant->id)
                ->delete();

            $remainingMemberships = UserTenant::query()->where('user_id', $baseUserId)->count();
            if ($remainingMemberships === 0) {
                BaseUser::query()->whereKey($baseUserId)->delete();
            }
        });

        return ApiResponse::success('Tenant staff deleted');
    }

    /**
     * Map staff provisioning error.
     */
    private function mapStaffProvisioningError(string $message): array
    {
        return match ($message) {
            'Owner role cannot be assigned through staff provisioning.',
            'Owner role cannot be assigned through the staff endpoint.' => [
                'roles' => ['The owner role cannot be assigned from this endpoint.'],
            ],
            'One or more tenant roles are invalid.' => [
                'roles' => ['One or more selected roles are not available in this workspace.'],
            ],
            'A staff account with the same phone or email already exists in this workspace.' => [
                'staff' => ['A staff account with the same phone or email already exists in this workspace.'],
            ],
            default => [
                'staff' => ['Please review the submitted staff details and try again.'],
            ],
        };
    }
}

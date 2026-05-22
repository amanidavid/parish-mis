<?php

namespace App\Http\Controllers\Api\App\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\V1\PermissionIndexRequest;
use App\Http\Requests\Api\App\V1\RoleIndexRequest;
use App\Http\Requests\Api\App\V1\StorePermissionRequest;
use App\Http\Requests\Api\App\V1\SyncRolePermissionsRequest;
use App\Http\Requests\Api\App\V1\SyncUserDirectPermissionsRequest;
use App\Http\Resources\App\V1\PermissionResource;
use App\Http\Resources\App\V1\RoleResource;
use App\Http\Resources\App\V1\TenantUserResource;
use App\Models\Tenant\User as TenantUser;
use App\Services\V1\PermissionService;
use App\Support\ApiResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AccessControlController extends Controller
{
    public function __construct(private PermissionService $permissionService)
    {
    }

    public function permissions(PermissionIndexRequest $request)
    {
        $this->ensureAccessControlPermission();

        $filters = $request->validated();
        $query = Permission::query()
            ->where('guard_name', 'api')
            ->orderBy('module')
            ->orderBy('name');

        if (!empty($filters['module'] ?? null)) {
            $query->where('module', 'like', strtolower($filters['module']).'%');
        }

        if (!empty($filters['name'] ?? null)) {
            $query->where('name', 'like', strtolower($filters['name']).'%');
        }

        $permissions = $query->paginate((int) ($filters['per_page'] ?? 50));

        return ApiResponse::resource(PermissionResource::collection($permissions), 'Permission list');
    }

    public function storePermission(StorePermissionRequest $request)
    {
        $this->ensureAccessControlPermission();

        $permission = $this->permissionService->createPermission($request->validated('name'));

        return ApiResponse::resource(new PermissionResource($permission), 'Permission created', 201);
    }

    public function roles(RoleIndexRequest $request)
    {
        $this->ensureAccessControlPermission();

        $filters = $request->validated();
        $query = Role::query()
            ->where('guard_name', 'api')
            ->withCount('permissions')
            ->orderBy('name');

        if (!empty($filters['name'] ?? null)) {
            $query->where('name', 'like', strtolower($filters['name']).'%');
        }

        $roles = $query->paginate((int) ($filters['per_page'] ?? 25));

        return ApiResponse::resource(RoleResource::collection($roles), 'Role list');
    }

    public function showRole(int $roleId)
    {
        $this->ensureAccessControlPermission();

        $role = Role::query()
            ->where('guard_name', 'api')
            ->with(['permissions' => fn ($permissionQuery) => $permissionQuery->orderBy('module')->orderBy('name')])
            ->findOrFail($roleId);

        return ApiResponse::resource(new RoleResource($role), 'Role details');
    }

    public function syncRolePermissions(SyncRolePermissionsRequest $request, int $roleId)
    {
        $this->ensureAccessControlPermission();

        $role = Role::query()->where('guard_name', 'api')->findOrFail($roleId);
        $permissions = $this->permissionService->findByIds($request->validated('permission_ids'));

        if ($permissions->count() !== count($request->validated('permission_ids'))) {
            return ApiResponse::error('Role permission update failed', ['permissions' => ['One or more permissions are invalid.']], 422);
        }

        $role->syncPermissions($permissions);
        $role->load(['permissions' => fn ($permissionQuery) => $permissionQuery->orderBy('module')->orderBy('name')]);

        return ApiResponse::resource(new RoleResource($role), 'Role permissions updated');
    }

    public function syncUserDirectPermissions(SyncUserDirectPermissionsRequest $request, TenantUser $tenantUser)
    {
        $this->ensureAccessControlPermission();

        $permissions = $this->permissionService->findByIds($request->validated('permission_ids'));

        if ($permissions->count() !== count($request->validated('permission_ids'))) {
            return ApiResponse::error('User direct permission update failed', ['permissions' => ['One or more permissions are invalid.']], 422);
        }

        $tenantUser->syncPermissions($permissions);
        $tenantUser->load([
            'baseUser',
            'roles.permissions',
            'permissions' => fn ($permissionQuery) => $permissionQuery->orderBy('module')->orderBy('name'),
        ]);

        return ApiResponse::resource(new TenantUserResource($tenantUser), 'User direct permissions updated');
    }

    private function ensureAccessControlPermission(): void
    {
        $tenantUser = request()->user();

        abort_unless(
            $tenantUser instanceof TenantUser && $tenantUser->hasPermissionTo('roles.manage'),
            403,
            'You do not have permission to manage roles and permissions.'
        );
    }
}

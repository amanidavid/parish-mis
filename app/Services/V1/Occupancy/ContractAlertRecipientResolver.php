<?php

namespace App\Services\V1\Occupancy;

use App\Models\Tenant\User;
use App\Services\V1\PropertyAssignmentAccessService;
use Illuminate\Support\Facades\DB;

class ContractAlertRecipientResolver
{
    /**
     * Resolve property alert recipients.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function resolveForProperties(array $propertyIds): array
    {
        return $this->resolveForPropertiesWithPermissions(
            $propertyIds,
            (array) config('contract_alerts.staff_permissions', [])
        );
    }

    /**
     * Resolve property alert recipients with permissions.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function resolveForPropertiesWithPermissions(array $propertyIds, array $permissionNames): array
    {
        $propertyIds = collect($propertyIds)
            ->map(fn ($propertyId) => (int) $propertyId)
            ->filter(fn (int $propertyId) => $propertyId > 0)
            ->unique()
            ->values()
            ->all();

        if ($propertyIds === []) {
            return [];
        }

        if (!is_array($permissionNames) || $permissionNames === []) {
            return array_fill_keys($propertyIds, []);
        }

        $eligibleUsers = $this->distinctUserIdsByPermissionsQuery($permissionNames);
        $bypassUsers = $this->distinctUserIdsByPermissionsQuery([PropertyAssignmentAccessService::BYPASS_PERMISSION]);

        $assignedRows = DB::table('users')
            ->joinSub($eligibleUsers, 'eligible_users', fn ($join) => $join->on('eligible_users.model_id', '=', 'users.id'))
            ->join('staff_property_assignments', 'staff_property_assignments.user_id', '=', 'users.id')
            ->where('users.status', 'active')
            ->whereIn('staff_property_assignments.property_id', $propertyIds)
            ->select([
                'staff_property_assignments.property_id',
                'users.id',
                'users.uuid',
                'users.name',
                'users.email',
                'users.phone',
            ])
            ->distinct()
            ->get();

        $globalRows = DB::table('users')
            ->joinSub($eligibleUsers, 'eligible_users', fn ($join) => $join->on('eligible_users.model_id', '=', 'users.id'))
            ->joinSub($bypassUsers, 'bypass_users', fn ($join) => $join->on('bypass_users.model_id', '=', 'users.id'))
            ->where('users.status', 'active')
            ->select([
                'users.id',
                'users.uuid',
                'users.name',
                'users.email',
                'users.phone',
            ])
            ->distinct()
            ->get()
            ->map(fn ($row) => $this->formatRecipient($row));

        $recipientsByProperty = [];
        foreach ($propertyIds as $propertyId) {
            $recipientsByProperty[$propertyId] = [];
        }

        foreach ($assignedRows as $row) {
            $propertyId = (int) $row->property_id;
            $recipientsByProperty[$propertyId][$this->recipientKey((int) $row->id)] = $this->formatRecipient($row);
        }

        foreach ($propertyIds as $propertyId) {
            foreach ($globalRows as $recipient) {
                $recipientsByProperty[$propertyId][$recipient['recipient_key']] = $recipient;
            }

            $recipientsByProperty[$propertyId] = array_values($recipientsByProperty[$propertyId]);
        }

        return $recipientsByProperty;
    }

    /**
     * Build distinct user ids query.
     */
    private function distinctUserIdsByPermissionsQuery(array $permissionNames)
    {
        $permissionNames = array_values(array_filter(array_map(
            static fn (string $permission): string => trim($permission),
            $permissionNames
        )));

        if ($permissionNames === []) {
            return DB::table('users')->whereRaw('1 = 0')->selectRaw('id as model_id');
        }

        $directPermissions = DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_type', User::class)
            ->where('permissions.guard_name', 'api')
            ->whereIn('permissions.name', $permissionNames)
            ->select('model_has_permissions.model_id');

        $rolePermissions = DB::table('model_has_roles')
            ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('permissions.guard_name', 'api')
            ->whereIn('permissions.name', $permissionNames)
            ->select('model_has_roles.model_id');

        return DB::query()
            ->fromSub($directPermissions->union($rolePermissions), 'permission_user_ids')
            ->select('model_id')
            ->distinct();
    }

    /**
     * Format recipient.
     *
     * @return array<string, mixed>
     */
    private function formatRecipient(object $row): array
    {
        return [
            'recipient_type' => 'staff',
            'recipient_key' => $this->recipientKey((int) $row->id),
            'user_id' => (int) $row->id,
            'uuid' => $row->uuid,
            'name' => $row->name,
            'email' => $row->email,
            'phone' => $row->phone,
        ];
    }

    /**
     * Recipient key.
     */
    private function recipientKey(int $userId): string
    {
        return 'staff:'.$userId;
    }
}

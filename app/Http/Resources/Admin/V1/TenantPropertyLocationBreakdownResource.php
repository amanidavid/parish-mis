<?php

namespace App\Http\Resources\Admin\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class TenantPropertyLocationBreakdownResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'location' => [
                'uuid' => $this->group_uuid,
                'name' => $this->group_name,
            ],
            'country' => $this->country_uuid ? [
                'uuid' => $this->country_uuid,
                'name' => $this->country_name,
            ] : null,
            'region' => $this->region_uuid ? [
                'uuid' => $this->region_uuid,
                'name' => $this->region_name,
            ] : null,
            'district' => $this->district_uuid ? [
                'uuid' => $this->district_uuid,
                'name' => $this->district_name,
            ] : null,
            'ward' => $this->ward_uuid ? [
                'uuid' => $this->ward_uuid,
                'name' => $this->ward_name,
            ] : null,
            'properties_count' => (int) $this->properties_count,
        ];
    }
}

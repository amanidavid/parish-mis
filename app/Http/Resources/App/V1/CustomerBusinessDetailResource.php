<?php

namespace App\Http\Resources\App\V1;

use App\Http\Resources\ApiJsonResource;
use Illuminate\Http\Request;

class CustomerBusinessDetailResource extends ApiJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'business_name' => $this->business_name,
            'registration_number' => $this->registration_number,
            'tax_identifier' => $this->tax_identifier,
            'contact_person_name' => $this->contact_person_name,
            'contact_person_phone' => $this->contact_person_phone,
            'address_line' => $this->address_line,
            ...$this->timestamps(),
        ];
    }
}

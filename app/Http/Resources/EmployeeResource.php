<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...EmployeeIndexResource::make($this->resource)->resolve($request),
            'user_id' => $this->resource->user_id,
            'branch_id' => $this->resource->branch_id,
            'current_position_id' => $this->resource->current_position_id,
            'shift_id' => $this->resource->shift_id,
            'manager_id' => $this->resource->manager_id,
            'date_of_birth' => $this->resource->date_of_birth?->toJSON(),
            'gender' => $this->resource->gender?->value,
            'personal_phone' => $this->resource->personal_phone,
            'personal_email' => $this->resource->personal_email,
            'id_type' => $this->resource->id_type?->value,
            'id_number' => $this->resource->id_number,
            'emergency_contact_name' => $this->resource->emergency_contact_name,
            'emergency_contact_relationship' => $this->resource->emergency_contact_relationship?->value,
            'emergency_contact_phone' => $this->resource->emergency_contact_phone,
            'confirmation_date' => $this->resource->confirmation_date?->toJSON(),
            'termination_date' => $this->resource->termination_date?->toJSON(),
            'last_working_date' => $this->resource->last_working_date?->toJSON(),
            'user' => $this->when(
                $this->resource->relationLoaded('user') && $this->resource->user !== null,
                fn (): array => [
                    'id' => $this->resource->user->id,
                    'name' => $this->resource->user->name,
                    'email' => $this->resource->user->email,
                ],
            ),
            'educations' => $this->when(
                $this->resource->relationLoaded('educations'),
                fn () => EmployeeEducationResource::collection($this->resource->educations),
            ),
            'emergency_contacts' => $this->when(
                $this->resource->relationLoaded('emergencyContacts'),
                fn () => EmployeeEmergencyContactResource::collection($this->resource->emergencyContacts),
            ),
            'addresses' => $this->when(
                $this->resource->relationLoaded('addresses'),
                fn () => EmployeeAddressResource::collection($this->resource->addresses),
            ),
            'employee_positions' => $this->when(
                $this->resource->relationLoaded('employeePositions'),
                fn () => EmployeePositionResource::collection($this->resource->employeePositions),
            ),
        ];
    }
}

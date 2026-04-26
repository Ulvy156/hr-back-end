<?php

namespace App\Http\Requests\User;

use App\Models\Role;
use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncUserRolesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user('api') ?? $this->user();

        return $user?->can(PermissionName::UserRoleAssign->value) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'max:1'],
            'roles.*' => [
                'string',
                'distinct',
                Rule::in(Role::managedRoleNames()),
                Rule::exists(Role::class, 'name')->where(
                    fn ($query) => $query->where('guard_name', 'api')
                ),
            ],
        ];
    }
}

<?php

namespace App\Http\Requests\User;

use App\Models\Permission;
use App\Models\Role;
use App\PermissionName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SyncUserAccessRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user('api') ?? $this->user();

        if ($user === null) {
            return false;
        }

        $includesRoles = array_key_exists('roles', $this->all());
        $includesPermissions = array_key_exists('permissions', $this->all());

        if (! $includesRoles && ! $includesPermissions) {
            return false;
        }

        if ($includesRoles && ! $user->can(PermissionName::UserRoleAssign->value)) {
            return false;
        }

        return ! $includesPermissions || $user->can(PermissionName::UserPermissionAssign->value);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'roles' => ['sometimes', 'array', 'max:1'],
            'roles.*' => [
                'string',
                'distinct',
                Rule::in(Role::managedRoleNames()),
                Rule::exists(Role::class, 'name')->where(
                    fn ($query) => $query->where('guard_name', 'api')
                ),
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [
                'string',
                'distinct',
                Rule::exists(Permission::class, 'name')->where(
                    fn ($query) => $query->where('guard_name', 'api')
                ),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_key_exists('roles', $this->all()) || array_key_exists('permissions', $this->all())) {
                return;
            }

            $validator->errors()->add(
                'access',
                'At least one of roles or permissions must be provided.',
            );
        });
    }
}

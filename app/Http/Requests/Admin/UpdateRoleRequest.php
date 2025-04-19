<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('acl.acp.edit');
    }

    public function rules()
    {
        $roleId = $this->route('role')->id;

        return [
            'name' => [
                'required','string',
                Rule::unique('roles','name')->ignore($roleId),
            ],
            'guard_name'    => 'required|string|max:255',
            'permissions'   => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
        ];
    }
}

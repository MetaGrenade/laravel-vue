<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoleRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('acl.acp.create');
    }

    public function rules()
    {
        return [
            'name'          => 'required|string|max:255|unique:roles,name',
            'guard_name'    => 'required|string|max:255',
            'permissions'   => 'nullable|array',
            'permissions.*' => 'string|exists:permissions,name',
        ];
    }
}

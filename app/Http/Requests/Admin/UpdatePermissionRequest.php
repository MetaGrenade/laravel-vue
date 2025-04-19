<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermissionRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('acl.acp.edit');
    }

    public function rules()
    {
        $permId = $this->route('permission')->id;
        return [
            'name' => [
                'required','string',
                Rule::unique('permissions','name')->ignore($permId),
            ],
            'guard_name'  => 'required|string|max:255',
        ];
    }
}

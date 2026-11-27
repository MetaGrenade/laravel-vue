<?php

namespace App\Http\Requests\Admin;

use App\Rules\NotShapedLikeAnId;
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
                'required', 'string', 'max:255',
                Rule::unique('permissions', 'name')->ignore($permId),
                new NotShapedLikeAnId,
            ],
            'guard_name' => 'required|string|max:255',
        ];
    }
}

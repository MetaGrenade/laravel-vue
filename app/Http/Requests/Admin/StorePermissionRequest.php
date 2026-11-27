<?php

namespace App\Http\Requests\Admin;

use App\Rules\NotShapedLikeAnId;
use Illuminate\Foundation\Http\FormRequest;

class StorePermissionRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('acl.acp.create');
    }

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:permissions,name', new NotShapedLikeAnId],
            'guard_name' => 'required|string|max:255',
        ];
    }
}

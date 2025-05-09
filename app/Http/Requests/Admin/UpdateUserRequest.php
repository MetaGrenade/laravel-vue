<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('users.acp.edit');
    }

    public function rules()
    {
        $userId = $this->route('user')->id;

        return [
            'nickname'  => [
                'required',
                'string',
                'max:255',
                Rule::unique('users','nickname')->ignore($userId)
            ],
            'email' => [
                'required','email',
                Rule::unique('users','email')->ignore($userId),
            ],
            'roles' => 'nullable|array',
            'roles.*' => 'string|exists:roles,name',
        ];
    }
}

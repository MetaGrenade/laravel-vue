<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('users.acp.create');
    }

    public function rules()
    {
        return [
            'nickname'  => 'required|string|max:255|unique:users,nickname',
            'email' => 'required|email|unique:users,email',
            'roles' => 'nullable|array',
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BlogRequest extends FormRequest
{
    public function authorize()
    {
        // Adjust authorization logic as needed (set to true if using controller middleware)
        return true;
    }

    public function rules()
    {
        return [
            'title'    => 'required|string|max:255',
            'excerpt'  => 'nullable|string',
            'body'  => 'required|string',
            'status'   => 'required|in:draft,published,archived',
            'cover_image' => 'nullable|image|max:5120',
        ];
    }
}

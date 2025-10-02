<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreFaqRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('support.acp.create');
    }

    public function rules()
    {
        return [
            'faq_category_id' => 'required|integer|exists:faq_categories,id',
            'question'  => 'required|string|max:255',
            'answer'    => 'required|string',
            'order'     => 'integer',
            'published' => 'boolean',
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFaqRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('support.acp.edit');
    }

    public function rules()
    {
        return [
            'faq_category_id' => 'sometimes|required|integer|exists:faq_categories,id',
            'question'  => 'sometimes|required|string|max:255',
            'answer'    => 'sometimes|required|string',
            'order'     => 'integer',
            'published' => 'boolean',
        ];
    }
}

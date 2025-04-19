<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupportTicketRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('support.acp.create');
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'user_id' => $this->user()->id,
        ]);
    }

    public function rules()
    {
        return [
            'subject'  => 'required|string|max:255',
            'body'     => 'required|string',
            'priority' => 'in:low,medium,high',
            'user_id'     => 'required|exists:users,id',
            // add any other fields
        ];
    }
}

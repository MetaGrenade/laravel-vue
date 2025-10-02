<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupportTicketRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('support.acp.create');
    }

    public function rules()
    {
        return [
            'subject'  => 'required|string|max:255',
            'body'     => 'required|string',
            'priority' => 'in:low,medium,high',
            'user_id'  => 'nullable|exists:users,id',
            // add any other fields
        ];
    }
}

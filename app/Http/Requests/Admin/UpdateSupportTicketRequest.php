<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupportTicketRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()->can('support.acp.edit');
    }

    public function rules()
    {
        return [
            'subject'    => 'sometimes|required|string|max:255',
            'body'       => 'sometimes|required|string',
            'status'     => 'sometimes|required|in:open,closed',
            'priority'   => 'in:low,medium,high',
            'assigned_to'=> 'nullable|exists:users,id',
            // etc...
        ];
    }
}

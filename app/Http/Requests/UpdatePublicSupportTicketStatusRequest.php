<?php

namespace App\Http\Requests;

use App\Models\SupportTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePublicSupportTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof SupportTicket
            && (bool) $this->user()?->can('close', $ticket);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['closed'])],
        ];
    }
}

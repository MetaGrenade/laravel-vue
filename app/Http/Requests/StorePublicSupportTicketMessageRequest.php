<?php

namespace App\Http\Requests;

use App\Models\SupportTicket;
use Illuminate\Foundation\Http\FormRequest;

class StorePublicSupportTicketMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof SupportTicket
            && $this->user()
            && $ticket->user_id === $this->user()->id;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:3', 'max:5000'],
        ];
    }
}

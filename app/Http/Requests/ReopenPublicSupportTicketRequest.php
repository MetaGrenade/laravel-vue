<?php

namespace App\Http\Requests;

use App\Models\SupportTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReopenPublicSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ticket = $this->route('ticket');

        return $ticket instanceof SupportTicket
            && $this->user()
            && (int) $ticket->user_id === (int) $this->user()->id;
    }

    public function rules(): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ticket = $this->route('ticket');

            if (! $ticket instanceof SupportTicket) {
                return;
            }

            if ($ticket->status !== 'closed') {
                $validator->errors()->add('status', 'This ticket is already open.');
            }
        });
    }
}

<?php

namespace App\Http\Requests;

use App\Models\SupportTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePublicSupportTicketRatingRequest extends FormRequest
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
            'rating' => ['required', 'integer', Rule::in([1, 2, 3, 4, 5])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ticket = $this->route('ticket');

            if (! $ticket instanceof SupportTicket) {
                return;
            }

            if ($ticket->status !== 'closed') {
                $validator->errors()->add('rating', 'You can only rate a ticket after it has been closed.');
            }

            if ($ticket->customer_satisfaction_rating !== null) {
                $validator->errors()->add('rating', 'This ticket has already been rated.');
            }
        });
    }
}

<?php

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A review as a customer writes it: a rating, an optional headline and the text. All of it is plain text.
 */
class ProductReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $titleMax = (int) config('commerce.reviews.title_max', 120);
        $bodyMin = (int) config('commerce.reviews.body_min', 10);
        $bodyMax = (int) config('commerce.reviews.body_max', 5000);

        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', "max:{$titleMax}"],
            'body' => ['required', 'string', "min:{$bodyMin}", "max:{$bodyMax}"],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.required' => 'Choose a rating from 1 to 5 stars.',
            'rating.between' => 'Choose a rating from 1 to 5 stars.',
            'body.required' => 'Tell others what you thought of it.',
            'body.min' => 'Write a little more: at least :min characters.',
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\Blog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportBlogCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $reasons = config('forum.report_reasons', []);

        return [
            'reason_category' => ['required', 'string', Rule::in(array_keys($reasons))],
            'reason' => ['nullable', 'string', 'max:1000'],
            'evidence_url' => ['nullable', 'string', 'max:2048', 'url'],
        ];
    }
}

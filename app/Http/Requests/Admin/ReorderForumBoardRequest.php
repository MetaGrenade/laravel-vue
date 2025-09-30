<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ReorderForumBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('forums.acp.move') ?? false;
    }

    public function rules(): array
    {
        return [
            'direction' => ['required', 'in:up,down'],
        ];
    }
}

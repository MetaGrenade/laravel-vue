<?php

namespace App\Http\Requests\Admin\Catalogue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One or more files uploaded for a product to deliver. Any kind of file may be sold; what protects
 * the shop is how files are kept (a private disk, a generated name) and served (always as an
 * attachment of no particular type), not what they are called.
 */
class ProductFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $max = (int) config('commerce.downloads.max_per_product', 20);
        $kilobytes = (int) config('commerce.downloads.max_kilobytes', 102400);

        return [
            'files' => ['required', 'array', 'min:1', "max:{$max}"],
            'files.*' => ['file', "max:{$kilobytes}"],
            // The label for a single file; with several, each is labelled by its own name.
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $megabytes = round((int) config('commerce.downloads.max_kilobytes', 102400) / 1024, 1);

        return [
            'files.required' => 'Choose a file to upload.',
            'files.*.max' => "Each file can be at most {$megabytes} MB.",
            'files.*.file' => 'That upload did not arrive. Try again.',
            'files.*.uploaded' => 'That upload did not arrive, perhaps because it is bigger than the server accepts.',
        ];
    }
}

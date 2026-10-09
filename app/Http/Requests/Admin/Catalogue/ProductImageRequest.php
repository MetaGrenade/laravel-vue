<?php

namespace App\Http\Requests\Admin\Catalogue;

use App\Support\Commerce\Catalogue\ImageProcessor;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One or more pictures uploaded together. The cheap checks are here (a file, a plausible type,
 * a size); the real check is {@see ImageProcessor}, which decodes
 * the file and so refuses anything that is not a picture whatever it is called.
 */
class ProductImageRequest extends FormRequest
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
        $max = (int) config('commerce.images.max_per_product', 12);
        $kilobytes = (int) config('commerce.images.max_kilobytes', 5120);

        return [
            'images' => ['required', 'array', 'min:1', "max:{$max}"],
            'images.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif', "max:{$kilobytes}"],
            'alt' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $megabytes = round((int) config('commerce.images.max_kilobytes', 5120) / 1024, 1);

        return [
            'images.required' => 'Choose a picture to upload.',
            'images.*.mimes' => 'Upload a JPEG, PNG, WebP or GIF picture.',
            'images.*.max' => "Each picture can be at most {$megabytes} MB.",
            'images.*.file' => 'That upload did not arrive. Try again.',
            'images.*.uploaded' => 'That upload did not arrive, perhaps because it is bigger than the server accepts.',
        ];
    }
}

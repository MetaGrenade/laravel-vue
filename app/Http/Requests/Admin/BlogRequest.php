<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BlogRequest extends FormRequest
{
    public function authorize()
    {
        // Adjust authorization logic as needed (set to true if using controller middleware)
        return true;
    }

    protected function prepareForValidation(): void
    {
        $author = $this->input('author');

        $scheduledFor = $this->input('scheduled_for');

        if (is_string($scheduledFor)) {
            $scheduledFor = trim($scheduledFor);

            if ($scheduledFor === '') {
                $this->merge(['scheduled_for' => null]);
            }
        } elseif ($scheduledFor === null || $this->missing('scheduled_for')) {
            $this->merge(['scheduled_for' => null]);
        }

        if (is_array($author)) {
            if (! array_key_exists('avatar_url', $author) || ! is_string($author['avatar_url']) || trim($author['avatar_url']) === '') {
                $author['avatar_url'] = null;
            }

            if (! array_key_exists('profile_bio', $author) || ! is_string($author['profile_bio']) || trim($author['profile_bio']) === '') {
                $author['profile_bio'] = null;
            }

            if (array_key_exists('social_links', $author)) {
                $socialLinks = $author['social_links'];

                if (! is_array($socialLinks)) {
                    $socialLinks = [];
                }

                $author['social_links'] = array_values(array_map(
                    fn ($link) => is_array($link) ? $link : [],
                    $socialLinks,
                ));
            }

            $this->merge(['author' => $author]);
        }
    }

    public function rules()
    {
        return [
            'title'    => 'required|string|max:255',
            'excerpt'  => 'nullable|string',
            'body'  => 'required|string',
            'status'   => 'required|in:draft,scheduled,published,archived',
            'scheduled_for' => 'nullable|date|after:now|required_if:status,scheduled',
            'cover_image' => 'nullable|image|max:5120',
            'category_ids' => 'array',
            'category_ids.*' => 'integer|exists:blog_categories,id',
            'tag_ids' => 'array',
            'tag_ids.*' => 'integer|exists:blog_tags,id',
            'author' => 'nullable|array',
            'author.avatar_url' => 'nullable|url|max:2048',
            'author.profile_bio' => 'nullable|string',
            'author.social_links' => 'nullable|array',
            'author.social_links.*.label' => 'nullable|string|max:255',
            'author.social_links.*.url' => 'nullable|url|max:2048',
        ];
    }
}

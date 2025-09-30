<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait ManagesForumStructure
{
    /**
     * Resolve a slug for the given model, ensuring uniqueness.
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function resolveSlug(?string $slug, string $title, string $modelClass, ?int $ignoreId = null): string
    {
        $candidate = Str::slug($slug ?: $title);

        if ($candidate === '') {
            $candidate = Str::random(8);
        }

        $original = $candidate;
        $suffix = 1;

        $query = $modelClass::query()->where('slug', $candidate);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        while ($query->exists()) {
            $candidate = $original.'-'.$suffix++;

            $query = $modelClass::query()->where('slug', $candidate);

            if ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            }
        }

        return $candidate;
    }

    protected function swapPositions(Model $first, Model $second): void
    {
        $firstPosition = $first->position;
        $secondPosition = $second->position;

        $first->forceFill(['position' => $secondPosition])->save();
        $second->forceFill(['position' => $firstPosition])->save();
    }

    protected function resequenceCategories(): void
    {
        ForumCategory::query()
            ->orderBy('position')
            ->get()
            ->values()
            ->each(function (ForumCategory $category, int $index) {
                $expected = $index + 1;

                if ($category->position !== $expected) {
                    $category->forceFill(['position' => $expected])->save();
                }
            });
    }

    protected function resequenceBoards(int $categoryId): void
    {
        ForumBoard::query()
            ->where('forum_category_id', $categoryId)
            ->orderBy('position')
            ->get()
            ->values()
            ->each(function (ForumBoard $board, int $index) {
                $expected = $index + 1;

                if ($board->position !== $expected) {
                    $board->forceFill(['position' => $expected])->save();
                }
            });
    }
}

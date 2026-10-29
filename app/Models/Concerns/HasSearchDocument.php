<?php

namespace App\Models\Concerns;

use App\Models\User;
use Laravel\Scout\Searchable;

/**
 * A record that may be listed in the search index. Whether it is listed is
 * {@see Searchable::shouldBeSearchable()} on the model. Turning that off
 * removes the document that was there.
 */
trait HasSearchDocument
{
    use Searchable;

    public function wasSearchableBeforeUpdate(): bool
    {
        $previous = $this->newInstance();
        $previous->setRawAttributes($this->getRawOriginal(), true);
        $previous->exists = true;

        return (bool) $previous->shouldBeSearchable();
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    protected function searchDocument(array $fields): array
    {
        return array_filter($fields, fn (string $value): bool => $value !== '');
    }

    protected function searchNickname(mixed $userId): string
    {
        if ($userId === null || $userId === '') {
            return '';
        }

        $name = User::query()->whereKey($userId)->value('nickname');

        return is_string($name) ? $name : '';
    }

    /**
     * The Meilisearch index for this model. Matches Scout's default of
     * prefixing {@see config('scout.prefix')} so settings sync and document
     * writes use the same name.
     */
    protected function searchIndexAs(string $name): string
    {
        return (string) config('scout.prefix').$name;
    }
}

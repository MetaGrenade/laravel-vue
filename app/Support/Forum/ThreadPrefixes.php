<?php

namespace App\Support\Forum;

use App\Models\ForumBoard;
use App\Models\ForumPrefix;
use App\Models\ForumThread;
use Illuminate\Validation\ValidationException;

/**
 * The prefixes a board offers, and which one a thread may carry (docs/forum-prefixes.md).
 */
class ThreadPrefixes
{
    /** @var list<string> */
    public const COLORS = ['slate', 'sky', 'green', 'amber', 'rose', 'violet'];

    /**
     * @return list<array{id: int, name: string, slug: string, color: string}>
     */
    public function choices(ForumBoard $board): array
    {
        $choices = [];

        foreach ($board->prefixes()->orderBy('position')->orderBy('name')->get() as $prefix) {
            $payload = $this->payload($prefix);

            if ($payload !== null) {
                $choices[] = $payload;
            }
        }

        return $choices;
    }

    /**
     * @return array{id: int, name: string, slug: string, color: string}|null
     */
    public function payload(?ForumPrefix $prefix): ?array
    {
        if ($prefix === null) {
            return null;
        }

        return [
            'id' => $prefix->id,
            'name' => $prefix->name,
            'slug' => $prefix->slug,
            'color' => $prefix->color,
        ];
    }

    /**
     * The prefix a web form asked for, by its id. An empty value is no prefix. A board that requires one refuses that
     * only when it actually offers some, and only for a thread that is being started.
     *
     * @throws ValidationException
     */
    public function resolveId(ForumBoard $board, mixed $value, bool $required): ?ForumPrefix
    {
        return $this->resolve($board, $value, $required, byId: true);
    }

    /**
     * The prefix an API request asked for, by its slug. A slug of digits is still a slug: it is never read as an id.
     *
     * @throws ValidationException
     */
    public function resolveSlug(ForumBoard $board, mixed $value, bool $required): ?ForumPrefix
    {
        return $this->resolve($board, $value, $required, byId: false);
    }

    /**
     * @throws ValidationException
     */
    private function resolve(ForumBoard $board, mixed $value, bool $required, bool $byId): ?ForumPrefix
    {
        $raw = is_string($value) ? trim($value) : $value;

        if ($raw === null || $raw === '') {
            if ($required && $board->prefixes()->exists()) {
                throw ValidationException::withMessages([
                    'prefix' => 'Choose a prefix for this board.',
                ]);
            }

            return null;
        }

        $prefix = null;

        if ($byId && (is_int($raw) || (is_string($raw) && ctype_digit($raw)))) {
            $prefix = $board->prefixes()->where('forum_prefixes.id', (int) $raw)->first();
        }

        if (! $byId && is_string($raw)) {
            $prefix = $board->prefixes()->where('forum_prefixes.slug', $raw)->first();
        }

        if (! $prefix instanceof ForumPrefix) {
            throw ValidationException::withMessages([
                'prefix' => 'That prefix is not available on this board.',
            ]);
        }

        return $prefix;
    }

    /**
     * Threads on a board that no longer offers this prefix lose it. The title stays.
     *
     * @param  list<int>  $boardIds
     */
    public function syncBoards(ForumPrefix $prefix, array $boardIds): void
    {
        $previous = [];

        foreach ($prefix->boards()->pluck('forum_boards.id') as $id) {
            $previous[] = (int) $id;
        }

        $prefix->boards()->sync($boardIds);

        $removed = array_values(array_diff($previous, $boardIds));

        if ($removed !== []) {
            ForumThread::query()
                ->where('forum_prefix_id', $prefix->id)
                ->whereIn('forum_board_id', $removed)
                ->update(['forum_prefix_id' => null]);
        }

        $prefix->touch();
    }
}

<?php

namespace App\Support\Members;

use App\Models\User;

/**
 * Finding a member from a few typed letters, for the pickers in the admin area (who a ticket is for, whose account a token is made on).
 *
 * A picker never receives every member: a page that listed them all was 1.4 MB at 20,000 members and grows with the site. It asks for the
 * ten that match what was typed, and for one by id when it was handed an id to show.
 */
class StaffUserSearch
{
    /** At most this many matches, however much was typed. */
    public const LIMIT = 10;

    /**
     * The members whose nickname or email contains the text, by nickname. Nothing for empty text: "everybody" is not an answer.
     *
     * @return list<array{id: int, nickname: string, email: string}>
     */
    public function matching(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        // What is typed is letters, not a pattern: `_` and `%` are escaped with `!` and the statement says so. A backslash would not do on SQLite,
        // which has no escape character unless the statement names one, and the lookup should find "john_d" the same on every database.
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';

        return User::query()
            ->where(function ($query) use ($like) {
                $query
                    ->whereRaw("lower(nickname) like ? escape '!'", [$like])
                    ->orWhereRaw("lower(email) like ? escape '!'", [$like]);
            })
            ->orderBy('nickname')
            ->limit(self::LIMIT)
            ->get(['id', 'nickname', 'email'])
            ->map(fn (User $match) => $this->row($match))
            ->all();
    }

    /**
     * The one member with this id (a list of one, to match what a search returns), or none.
     *
     * @return list<array{id: int, nickname: string, email: string}>
     */
    public function find(int $id): array
    {
        return User::query()
            ->whereKey($id)
            ->get(['id', 'nickname', 'email'])
            ->map(fn (User $match) => $this->row($match))
            ->all();
    }

    /**
     * @return array{id: int, nickname: string, email: string}
     */
    private function row(User $user): array
    {
        return ['id' => $user->id, 'nickname' => $user->nickname, 'email' => $user->email];
    }
}

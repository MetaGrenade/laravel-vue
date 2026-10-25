<?php

namespace App\Support\Forum;

use App\Models\ForumIgnore;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Who a member has chosen not to see in the forum (docs/forum-ignores.md).
 */
class IgnoredMembers
{
    /**
     * The people this member is ignoring.
     *
     * @return list<int>
     */
    public function ids(?User $viewer): array
    {
        if ($viewer === null) {
            return [];
        }

        $ids = [];

        foreach (ForumIgnore::query()->where('user_id', $viewer->id)->pluck('ignored_user_id') as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * The people who have chosen not to hear from this author.
     *
     * @return list<int>
     */
    public function quietFor(User $author): array
    {
        $ids = [];

        foreach (ForumIgnore::query()->where('ignored_user_id', $author->id)->pluck('user_id') as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    public function ignoring(User $viewer, User $member): bool
    {
        return ForumIgnore::query()
            ->where('user_id', $viewer->id)
            ->where('ignored_user_id', $member->id)
            ->exists();
    }

    public function canIgnore(User $viewer, User $member): bool
    {
        return $viewer->id !== $member->id && ! $member->hasAnyRole(['admin', 'editor', 'moderator']);
    }

    /**
     * @return list<array{id: int, nickname: string, banned: bool}>
     */
    public function listing(User $viewer): array
    {
        $rows = [];

        $ignores = ForumIgnore::query()
            ->where('user_id', $viewer->id)
            ->with('ignored:id,nickname,is_banned')
            ->orderBy('id')
            ->get();

        foreach ($ignores as $ignore) {
            $member = $ignore->ignored;

            if (! $member instanceof User) {
                continue;
            }

            $rows[] = [
                'id' => $member->id,
                'nickname' => (string) $member->nickname,
                'banned' => $member->is_banned,
            ];
        }

        return $rows;
    }

    /**
     * @throws ValidationException
     */
    public function ignore(User $viewer, User $member): void
    {
        if ($viewer->id === $member->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot ignore yourself.',
            ]);
        }

        if (! $this->canIgnore($viewer, $member)) {
            throw ValidationException::withMessages([
                'user' => 'Moderators cannot be ignored.',
            ]);
        }

        ForumIgnore::query()->firstOrCreate([
            'user_id' => $viewer->id,
            'ignored_user_id' => $member->id,
        ]);
    }

    public function forget(User $viewer, User $member): void
    {
        ForumIgnore::query()
            ->where('user_id', $viewer->id)
            ->where('ignored_user_id', $member->id)
            ->delete();
    }
}

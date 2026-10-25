<?php

namespace App\Support\Forum;

use App\Models\ForumIgnore;
use App\Models\User;
use App\Support\Routing\ZiggyRouteGroup;
use Illuminate\Validation\ValidationException;

/**
 * Who a member has chosen not to see in the forum (docs/forum-ignores.md).
 */
class IgnoredMembers
{
    /**
     * The people this member is ignoring.
     *
     * A moderator, an editor, or an administrator is left out. A row saved before that role
     * does not hide their posts (docs/forum-ignores.md).
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

        return $this->exceptStaff($ids);
    }

    /**
     * The people who have chosen not to hear from this author.
     *
     * Nobody is held quiet when the author is a moderator, an editor, or an administrator,
     * including when the row was saved before the role.
     *
     * @return list<int>
     */
    public function quietFor(User $author): array
    {
        if ($this->isStaff($author)) {
            return [];
        }

        $ids = [];

        foreach (ForumIgnore::query()->where('ignored_user_id', $author->id)->pluck('user_id') as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    public function ignoring(User $viewer, User $member): bool
    {
        if ($this->isStaff($member)) {
            return false;
        }

        return ForumIgnore::query()
            ->where('user_id', $viewer->id)
            ->where('ignored_user_id', $member->id)
            ->exists();
    }

    public function canIgnore(User $viewer, User $member): bool
    {
        return $viewer->id !== $member->id && ! $this->isStaff($member);
    }

    /**
     * @return list<array{id: int, nickname: string, banned: bool}>
     */
    public function listing(User $viewer): array
    {
        $rows = [];

        $ignores = ForumIgnore::query()
            ->where('user_id', $viewer->id)
            ->with(['ignored' => function ($query): void {
                $query->select('id', 'nickname', 'is_banned')->with('roles');
            }])
            ->orderBy('id')
            ->get();

        foreach ($ignores as $ignore) {
            $member = $ignore->ignored;

            if (! $member instanceof User || $this->isStaff($member)) {
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

    private function isStaff(User $member): bool
    {
        return $member->hasAnyRole(ZiggyRouteGroup::STAFF_ROLES);
    }

    /**
     * Drop people who are moderators, editors, or administrators.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function exceptStaff(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $staff = [];

        foreach (User::query()->whereIn('id', $ids)->with('roles')->get() as $member) {
            if ($this->isStaff($member)) {
                $staff[$member->id] = true;
            }
        }

        $kept = [];

        foreach ($ids as $id) {
            if (! isset($staff[$id])) {
                $kept[] = $id;
            }
        }

        return $kept;
    }
}

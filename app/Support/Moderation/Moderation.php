<?php

namespace App\Support\Moderation;

use App\Models\ModerationAction;
use App\Models\User;
use App\Notifications\AccountBanned;
use App\Notifications\BanLifted;
use App\Notifications\WarningIssued;
use App\Support\Localization\DateFormatter;
use App\Support\Routing\ZiggyRouteGroup;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What moderators do to a member: warn them, ban them (for good or until a date), lift a ban (docs/moderation.md).
 *
 * Everything done is written to `moderation_actions`, which is the member's history and is never changed afterwards. A warning has no
 * effect of its own: it is a notice, and any ban is a moderator's decision. A ban is kept on the user's own row too (`is_banned` and
 * the rest), as everything that enforces it reads that, and a ban with an end date lifts itself: at the next time the member tries to
 * sign in or make a request (so it is exact where it matters), and by a job every minute (so the list of members is right as well).
 */
class Moderation
{
    /**
     * Why this moderator may not do this to this member, in words to show them, or null when they may.
     *
     * @param  'warn'|'ban'|'unban'  $action
     */
    public function refusal(User $moderator, User $member, string $action): ?string
    {
        if ($moderator->is($member)) {
            return 'You cannot moderate your own account.';
        }

        if ($action === 'ban' && $member->hasRole('admin')) {
            return 'An administrator cannot be banned. Take the administrator role away first.';
        }

        if ($member->hasAnyRole(ZiggyRouteGroup::STAFF_ROLES) && ! $moderator->hasRole('admin')) {
            return 'Only an administrator can moderate a member of staff.';
        }

        return null;
    }

    /**
     * Warn a member. The warning is kept in their history and, unless the moderator says not to, they are emailed.
     *
     * @param  array{type: string, id: int, label: string}|null  $subject  What it is about (see {@see ModerationSubject}).
     *
     * @throws ModerationRefused
     */
    public function warn(User $member, User $moderator, string $reason, ?string $note = null, ?array $subject = null, bool $notify = true): ModerationAction
    {
        $this->allow($moderator, $member, 'warn');

        $action = ModerationAction::query()->create([
            'user_id' => $member->id,
            'actor_id' => $moderator->id,
            'type' => ModerationAction::WARNING,
            'reason' => trim($reason),
            'note' => $this->clean($note),
            'subject_type' => $subject['type'] ?? null,
            'subject_id' => $subject['id'] ?? null,
            'subject_label' => $subject['label'] ?? null,
            'notified' => $notify,
        ]);

        if ($notify) {
            $member->notify(new WarningIssued(trim($reason), $subject['label'] ?? null));
        }

        return $action;
    }

    /**
     * Ban a member until a moderator lifts it, or until a date.
     *
     * @param  array{type: string, id: int, label: string}|null  $subject
     *
     * @throws ModerationRefused
     */
    public function ban(User $member, User $moderator, string $reason, ?CarbonInterface $until = null, ?string $note = null, ?array $subject = null, bool $notify = true): ModerationAction
    {
        $this->allow($moderator, $member, 'ban');

        if ($until !== null && ! $until->isFuture()) {
            throw new ModerationRefused('A ban has to end in the future.');
        }

        $reason = trim($reason);
        // Kept to the second, and in the application's time zone. MySQL rounds a fraction of a second when it stores one, so the end of a day
        // (23:59:59.999999) would become the start of the next; and a date is stored as the clock reads in the zone it carries, so the end
        // of a day in a moderator's own zone would be stored as that time in the application's.
        $until = $until?->copy()->startOfSecond()->setTimezone((string) config('app.timezone'));

        $action = DB::transaction(function () use ($member, $moderator, $reason, $until, $note, $subject, $notify) {
            // Two moderators banning the same member at once is one ban and one refusal, not two bans.
            $fresh = User::query()->lockForUpdate()->findOrFail($member->id);

            if ($fresh->is_banned && ! $this->hasRunOut($fresh)) {
                throw new ModerationRefused("{$fresh->nickname} is already banned.");
            }

            $fresh->forceFill([
                'is_banned' => true,
                'banned_at' => now(),
                'banned_by_id' => $moderator->id,
                'banned_until' => $until,
                'ban_reason' => $reason,
            ])->save();

            return ModerationAction::query()->create([
                'user_id' => $member->id,
                'actor_id' => $moderator->id,
                'type' => ModerationAction::BAN,
                'reason' => $reason,
                'note' => $this->clean($note),
                'expires_at' => $until,
                'subject_type' => $subject['type'] ?? null,
                'subject_id' => $subject['id'] ?? null,
                'subject_label' => $subject['label'] ?? null,
                'notified' => $notify,
            ]);
        });

        $member->refresh();

        if ($notify) {
            $member->notify(new AccountBanned($reason, $until));
        }

        return $action;
    }

    /**
     * Lift a ban.
     *
     * @throws ModerationRefused
     */
    public function unban(User $member, User $moderator, ?string $reason = null, bool $notify = true): ModerationAction
    {
        $this->allow($moderator, $member, 'unban');

        return $this->lift($member, $moderator, $reason, $notify);
    }

    /**
     * Lift the ban of a member whose end date has passed, if it has. Returns whether it did.
     */
    public function liftIfRunOut(User $member): bool
    {
        if (! $this->hasRunOut($member)) {
            return false;
        }

        try {
            $this->lift($member, null, null, true, onlyIfRunOut: true);
        } catch (ModerationRefused) {
            // Lifted by somebody else a moment ago: nothing left to do.
            $member->refresh();

            return false;
        }

        return true;
    }

    /**
     * Lift every ban that has run out. Returns how many.
     */
    public function liftRunOut(): int
    {
        $lifted = 0;

        User::query()
            ->where('is_banned', true)
            // A ban with no end date has a null here, which is not at or before anything.
            ->where('banned_until', '<=', now())
            ->chunkById(200, function (Collection $members) use (&$lifted) {
                foreach ($members as $member) {
                    $lifted += $this->liftIfRunOut($member) ? 1 : 0;
                }
            });

        return $lifted;
    }

    /**
     * Everything done to a member, newest first.
     *
     * @return Collection<int, ModerationAction>
     */
    public function history(User $member): Collection
    {
        return ModerationAction::query()
            ->with('actor:id,nickname')
            ->where('user_id', $member->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * What a banned member is told when they try to sign in: that they are banned, until when, and why.
     */
    public function banMessage(User $member): string
    {
        $message = $member->banned_until !== null
            ? trans('auth.banned_until', ['date' => DateFormatter::for($member)->dayDateTime($member->banned_until)])
            : trans('auth.banned');

        if (filled($member->ban_reason)) {
            $message .= ' '.trans('auth.banned_reason', ['reason' => trim((string) $member->ban_reason)]);
        }

        if (filled(config('moderation.contact'))) {
            $message .= ' '.trans('auth.banned_contact', ['contact' => config('moderation.contact')]);
        }

        return $message;
    }

    private function hasRunOut(User $member): bool
    {
        return $member->is_banned && $member->banned_until !== null && $member->banned_until->isPast();
    }

    /**
     * @throws ModerationRefused
     */
    private function allow(User $moderator, User $member, string $action): void
    {
        $refusal = $this->refusal($moderator, $member, $action);

        if ($refusal !== null) {
            throw new ModerationRefused($refusal);
        }
    }

    /**
     * Take a ban off, as a moderator or (with none) because it ran out.
     *
     * @param  bool  $onlyIfRunOut  Lift it only if its end date has passed, as seen under the lock: the member in hand may be older than
     *                              the row, and a moderator may have banned them again since.
     *
     * @throws ModerationRefused
     */
    private function lift(User $member, ?User $moderator, ?string $reason, bool $notify, bool $onlyIfRunOut = false): ModerationAction
    {
        [$action, $tell] = DB::transaction(function () use ($member, $moderator, $reason, $notify, $onlyIfRunOut) {
            $fresh = User::query()->lockForUpdate()->findOrFail($member->id);

            if (! $fresh->is_banned || ($onlyIfRunOut && ! $this->hasRunOut($fresh))) {
                throw new ModerationRefused("{$fresh->nickname} is not banned.");
            }

            // A moderator chooses whether the member is told. A ban that ran out tells them only if they were told of the ban.
            $tell = $moderator !== null
                ? $notify
                : (ModerationAction::query()->where('user_id', $fresh->id)->where('type', ModerationAction::BAN)->latest('id')->value('notified') ?? true);

            $fresh->forceFill([
                'is_banned' => false,
                'banned_at' => null,
                'banned_by_id' => null,
                'banned_until' => null,
                'ban_reason' => null,
            ])->save();

            $action = ModerationAction::query()->create([
                'user_id' => $fresh->id,
                'actor_id' => $moderator?->id,
                'type' => ModerationAction::UNBAN,
                'reason' => $this->clean($reason),
                'notified' => (bool) $tell,
            ]);

            return [$action, (bool) $tell];
        });

        $member->refresh();

        if ($tell) {
            $member->notify(new BanLifted($moderator === null, $this->clean($reason)));
        }

        return $action;
    }

    private function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}

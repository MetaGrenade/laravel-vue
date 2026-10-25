<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModerationAction;
use App\Models\User;
use App\Support\Localization\DateFormatter;
use App\Support\Moderation\Moderation;
use App\Support\Moderation\ModerationRefused;
use App\Support\Moderation\ModerationSubject;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What moderators do to one member: see where they stand and what has been done, warn them, ban them, lift a ban (docs/moderation.md).
 * Who may is the route's `can:` (warning needs `users.acp.warn`, a ban or lifting one `users.acp.ban`); who may be moderated is
 * {@see Moderation::refusal()}.
 */
class UserModerationController extends Controller
{
    public function show(Request $request, User $user, Moderation $moderation): Response
    {
        /** @var User $moderator */
        $moderator = $request->user();

        abort_unless($moderator->can('users.acp.view'), 403);

        $formatter = DateFormatter::for($moderator);
        $subject = ModerationSubject::resolve($request->query('subject'), $user);

        return Inertia::render('acp/UserModeration', [
            'member' => [
                'id' => $user->id,
                'nickname' => $user->nickname,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'roles' => $user->roles()->pluck('name')->all(),
                'created_at' => $formatter->iso($user->created_at),
                'last_activity_at' => $formatter->iso($user->getAttribute('last_activity_at')),
                'forum_threads' => $user->forumThreads()->count(),
                'forum_posts' => $user->forumPosts()->count(),
            ],
            'standing' => [
                'banned' => (bool) $user->is_banned,
                'banned_at' => $formatter->iso($user->banned_at),
                'banned_until' => $formatter->iso($user->banned_until),
                'reason' => $user->ban_reason,
                'banned_by' => $user->bannedBy?->nickname,
            ],
            'history' => $moderation->history($user)->map(fn (ModerationAction $action) => [
                'id' => $action->id,
                'type' => $action->type,
                'reason' => $action->reason,
                'note' => $action->note,
                'actor' => $action->actor?->nickname,
                'expires_at' => $formatter->iso($action->expires_at),
                'notified' => $action->notified,
                'subject' => $action->subject_label !== null ? [
                    'label' => $action->subject_label,
                    'url' => ModerationSubject::url($action->subject_type, $action->subject_id),
                ] : null,
                'created_at' => $formatter->iso($action->created_at),
            ])->all(),
            // What the report this was opened from is about, so the forms can attach it.
            'subject' => $subject === null ? null : [
                'reference' => $subject['type'].':'.$subject['id'],
                'label' => $subject['label'],
                'url' => ModerationSubject::url($subject['type'], $subject['id']),
            ],
            'can' => [
                'warn' => $moderator->can('users.acp.warn'),
                'ban' => $moderator->can('users.acp.ban'),
            ],
            // Why this moderator may not do each, when they may not (oneself, staff, an administrator).
            'refusal' => [
                'warn' => $moderation->refusal($moderator, $user, 'warn'),
                'ban' => $moderation->refusal($moderator, $user, 'ban'),
                'unban' => $moderation->refusal($moderator, $user, 'unban'),
            ],
            'contact' => filled(config('moderation.contact')),
        ]);
    }

    public function warn(Request $request, User $user, Moderation $moderation): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
            'notify' => ['sometimes', 'boolean'],
            'subject' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $moderation->warn(
                $user,
                $request->user(),
                $validated['reason'],
                $validated['note'] ?? null,
                ModerationSubject::resolve($validated['subject'] ?? null, $user),
                $request->boolean('notify', true),
            );
        } catch (ModerationRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        return redirect()->route('acp.users.moderation.show', $user)->with('success', "{$user->nickname} has been warned.");
    }

    public function ban(Request $request, User $user, Moderation $moderation): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
            'until' => ['nullable', 'date_format:Y-m-d'],
            'notify' => ['sometimes', 'boolean'],
            'subject' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $moderation->ban(
                $user,
                $request->user(),
                $validated['reason'],
                $this->endOfDay($request->user(), $validated['until'] ?? null),
                $validated['note'] ?? null,
                ModerationSubject::resolve($validated['subject'] ?? null, $user),
                $request->boolean('notify', true),
            );
        } catch (ModerationRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        return redirect()->route('acp.users.moderation.show', $user)->with('success', "{$user->nickname} has been banned.");
    }

    public function unban(Request $request, User $user, Moderation $moderation): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'notify' => ['sometimes', 'boolean'],
        ]);

        try {
            $moderation->unban($user, $request->user(), $validated['reason'] ?? null, $request->boolean('notify', true));
        } catch (ModerationRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        return redirect()->route('acp.users.moderation.show', $user)->with('success', "The ban on {$user->nickname} has been lifted.");
    }

    /**
     * The end of the day a moderator chose, in their own time zone: "until the 3rd" means through the 3rd.
     */
    private function endOfDay(User $moderator, ?string $date): ?Carbon
    {
        return $date === null ? null : Carbon::createFromFormat('Y-m-d', $date, DateFormatter::for($moderator)->timezone())->endOfDay();
    }
}

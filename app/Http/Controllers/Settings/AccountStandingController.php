<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ModerationAction;
use App\Models\User;
use App\Support\Localization\DateFormatter;
use App\Support\Moderation\ModerationSubject;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The warnings a member has been given, and any ban they have been under, for them to read again (docs/moderation.md). What a
 * moderator wrote for staff only (the note) and who they were are not shown.
 */
class AccountStandingController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $formatter = DateFormatter::for($user);

        return Inertia::render('settings/AccountStanding', [
            'notices' => $user->moderationActions()
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (ModerationAction $action) => [
                    'id' => $action->id,
                    'type' => $action->type,
                    'reason' => $action->reason,
                    'expires_at' => $formatter->iso($action->expires_at),
                    'subject' => $action->subject_label !== null ? [
                        'label' => $action->subject_label,
                        'url' => ModerationSubject::url($action->subject_type, $action->subject_id),
                    ] : null,
                    'created_at' => $formatter->iso($action->created_at),
                ])
                ->all(),
        ]);
    }
}

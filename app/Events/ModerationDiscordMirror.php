<?php

namespace App\Events;

use App\Models\ModerationAction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once per recorded moderation action for Discord staff mirroring (MetaForger Discord companion).
 */
class ModerationDiscordMirror
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public ModerationAction $action)
    {
        $this->action->loadMissing(['member', 'actor']);
    }

    public function moderationAction(): ModerationAction
    {
        return $this->action;
    }

    public function moderationActionUrl(): string
    {
        return route('acp.users.moderation.show', $this->action->user_id);
    }
}

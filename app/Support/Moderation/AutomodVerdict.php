<?php

namespace App\Support\Moderation;

use App\Models\AutomodRule;

/**
 * What the rules decided about a post: which rule, and why in words (docs/automod.md).
 */
final class AutomodVerdict
{
    public function __construct(
        public readonly AutomodRule $rule,
        public readonly string $detail,
    ) {}

    /**
     * Whether the post is to be refused, and not kept for a moderator to look at.
     */
    public function rejects(): bool
    {
        return $this->rule->action === AutomodRule::REJECT;
    }

    /**
     * Said as a moderator reads it in the queue: the rule, and what it found.
     */
    public function reason(): string
    {
        return $this->rule->name.': '.$this->detail;
    }
}

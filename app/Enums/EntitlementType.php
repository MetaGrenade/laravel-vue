<?php

namespace App\Enums;

/**
 * What kind of thing an entitlement is.
 *
 * Only the two that need nothing else to work are here. A role, a badge or a download would have to change something
 * outside this layer (and a package that could hand out a role is a way to escalate privileges), so each is added when
 * something is wired to honour it.
 */
enum EntitlementType: string
{
    /** On or off: the account can use something. */
    case Feature = 'feature';

    /** A number: how many of something the account may have. Grants add up. */
    case Limit = 'limit';

    public function label(): string
    {
        return match ($this) {
            self::Feature => 'Feature',
            self::Limit => 'Limit',
        };
    }
}

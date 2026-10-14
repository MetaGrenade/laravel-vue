<?php

namespace App\Enums;

/**
 * Where a product review stands. Only a published review is shown to the public or counted in a rating.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting approval',
            self::Published => 'Published',
            self::Rejected => 'Rejected',
        };
    }
}

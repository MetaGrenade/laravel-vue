<?php

namespace App\Support\Localization;

use Illuminate\Support\Carbon;

/**
 * A time an admin form sent, as a time the site can keep.
 *
 * A `datetime-local` box holds a wall clock with no zone, so what the browser sends is the moment it names, as an ISO date with its zone
 * (`new Date(box).toISOString()`, `resources/js/lib/localTime.ts`). Kept as it came, `2027-03-10T16:00:00.000Z` is stored as `16:00`, and
 * read back by a site whose zone is not UTC as 16:00 there, which is another moment. The database holds the site's own wall clock, so the
 * moment is turned into the site's zone before it is saved. A time with no zone (what a script may send) is read in the site's zone, as it
 * always was.
 */
class BrowserTime
{
    public static function parse(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }
}

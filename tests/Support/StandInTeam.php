<?php

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for the `Team` model that 1.1 adds, so a test can make somebody act for a second owner. It is never saved: an owner is
 * only a morph class and a key to the records that point at it.
 */
class StandInTeam extends Model
{
    protected $guarded = [];

    public static function withId(int $id): static
    {
        return (new static)->forceFill(['id' => $id]);
    }
}

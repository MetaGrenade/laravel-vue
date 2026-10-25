<?php

namespace App\Jobs;

use App\Support\Moderation\Moderation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Lifts the ban of every member whose suspension has run out (docs/moderation.md). A member is also let back in the moment they try to
 * sign in or make a request, so this is for what is seen without them: the lists of members, and the email telling them they can return.
 */
class LiftRunOutBans implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(Moderation $moderation): void
    {
        $moderation->liftRunOut();
    }
}

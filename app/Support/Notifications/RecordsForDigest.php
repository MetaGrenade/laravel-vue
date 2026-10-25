<?php

namespace App\Support\Notifications;

/**
 * A notice that can be named in a daily digest: a title, a link on this site, and an optional excerpt.
 */
interface RecordsForDigest
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array;
}

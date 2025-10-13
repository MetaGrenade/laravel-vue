<?php

namespace App\Notifications\Concerns;

/**
 * Ensure broadcast notifications execute on the synchronous queue connection so that
 * websocket pushes are emitted immediately even when no queue worker is running.
 */
trait SendsBroadcastsSynchronously
{
    /**
     * @return array<string, string|null>
     */
    public function viaConnections(): array
    {
        return [
            'broadcast' => 'sync',
        ];
    }
}


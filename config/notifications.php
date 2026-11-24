<?php

use App\Support\Config\EnvNumber;

return [

    /*
    |--------------------------------------------------------------------------
    | How long a notification that has been read is kept
    |--------------------------------------------------------------------------
    |
    | A notification a member gets in the site (the bell in the header) is a row in `notifications`. The bell shows the ones that have
    | not been read, and no page lists one that has, so a read notification is a row nobody can see. `read_retention_days` is how long
    | one is kept after it was read: the daily `PruneReadNotifications` job deletes older ones, and 0 keeps them for ever.
    |
    | A notification that has not been read is never deleted, however old it is: it is still in the bell, and its count is what the
    | member sees.
    |
    */

    'read_retention_days' => EnvNumber::integer('NOTIFICATIONS_READ_RETENTION_DAYS', 30),

];

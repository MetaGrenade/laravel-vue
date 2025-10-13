<?php

use Spatie\Activitylog\ActivityLogger;

if (! function_exists('activity')) {
    function activity(?string $logName = null): ActivityLogger
    {
        /** @var ActivityLogger $logger */
        $logger = app(ActivityLogger::class);

        if ($logName !== null) {
            $logger->useLog($logName);
        }

        return $logger;
    }
}

<?php

return [
    'default_log_name' => env('ACTIVITY_LOG_DEFAULT_LOG_NAME', 'audit'),

    'activity_model' => Spatie\\Activitylog\\Models\\Activity::class,

    'table_name' => 'activity_log',
];


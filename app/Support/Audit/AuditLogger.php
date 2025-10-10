<?php

namespace App\Support\Audit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Spatie\Activitylog\Models\Activity;

class AuditLogger
{
    public static function log(
        string $event,
        string $description,
        array $properties = [],
        ?Authenticatable $actor = null,
        ?Model $subject = null,
    ): Activity {
        $logger = activity('audit')->event($event);

        if ($actor !== null) {
            $logger->causedBy($actor);
        }

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        $properties = self::enrichProperties($properties);

        return $logger
            ->withProperties($properties)
            ->log($description);
    }

    protected static function enrichProperties(array $properties): array
    {
        $request = request();

        if ($request) {
            $properties = Arr::where(
                $properties + [
                    'ip' => $properties['ip'] ?? $request->ip(),
                    'user_agent' => $properties['user_agent'] ?? $request->userAgent(),
                ],
                static fn ($value) => $value !== null && $value !== '',
            );
        }

        return $properties;
    }
}


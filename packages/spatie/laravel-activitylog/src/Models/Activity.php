<?php

namespace Spatie\Activitylog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Arr;

/**
 * @property int $id
 * @property string|null $log_name
 * @property string $description
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 * @property string|null $causer_type
 * @property int|string|null $causer_id
 * @property string|null $event
 * @property array $properties
 * @property string|null $batch_uuid
 */
class Activity extends Model
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->setTable((string) config('activitylog.table_name', 'activity_log'));
    }

    protected $guarded = [];

    protected $casts = [
        'properties' => 'array',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeInLog(Builder $query, string $logName): Builder
    {
        return $query->where('log_name', $logName);
    }

    public function scopeCausedBy(Builder $query, Model $causer): Builder
    {
        return $query
            ->where('causer_type', $causer->getMorphClass())
            ->where('causer_id', $causer->getKey());
    }

    public function scopeForEvent(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }

    public function changes(): array
    {
        $properties = $this->properties ?? [];

        return [
            'old' => Arr::get($properties, 'old', []),
            'attributes' => Arr::get($properties, 'attributes', []),
        ];
    }
}

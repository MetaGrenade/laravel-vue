<?php

namespace Spatie\Activitylog;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Spatie\Activitylog\Models\Activity;

class ActivityLogger
{
    protected ?Model $subject = null;

    protected ?Model $causer = null;

    protected ?string $event = null;

    protected array $properties = [];

    protected ?string $logName = null;

    protected ?string $batchUuid = null;

    public function __construct(
        protected ActivityLogStatus $status,
        protected ?Request $request = null,
        protected ?string $defaultLogName = null,
    ) {
        $this->defaultLogName = $defaultLogName ?? config('activitylog.default_log_name', 'default');
        $this->logName = $this->defaultLogName;
    }

    public function useLog(?string $logName): self
    {
        $this->logName = $logName ?: $this->defaultLogName;

        return $this;
    }

    public function performedOn(?Model $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function causedBy(?Model $causer): self
    {
        $this->causer = $causer;

        return $this;
    }

    public function event(?string $event): self
    {
        $this->event = $event ? trim($event) : null;

        return $this;
    }

    public function batch(?string $batchUuid): self
    {
        $this->batchUuid = $batchUuid ? trim($batchUuid) : null;

        return $this;
    }

    public function withProperty(string $name, mixed $value): self
    {
        Arr::set($this->properties, $name, $value);

        return $this;
    }

    public function withProperties(array|Arrayable $properties): self
    {
        $data = $properties instanceof Arrayable ? $properties->toArray() : $properties;

        $this->properties = array_replace_recursive($this->properties, $data);

        return $this;
    }

    public function withoutProperties(): self
    {
        $this->properties = [];

        return $this;
    }

    public function tap(callable $callback): self
    {
        $callback($this);

        return $this;
    }

    public function disableLogging(): self
    {
        $this->status->disable();

        return $this;
    }

    public function enableLogging(): self
    {
        $this->status->enable();

        return $this;
    }

    public function log(string $description): ?Activity
    {
        if (! $this->status->enabled()) {
            return null;
        }

        $description = trim($description);

        if ($description === '') {
            return null;
        }

        $properties = $this->properties;

        if ($this->request) {
            $properties = array_replace_recursive([
                'request' => [
                    'ip' => $this->request->ip(),
                    'user_agent' => $this->request->userAgent(),
                    'url' => $this->request->fullUrl(),
                ],
            ], $properties);
        }

        $activity = Activity::create([
            'log_name' => $this->logName,
            'description' => $description,
            'subject_type' => $this->subject?->getMorphClass(),
            'subject_id' => $this->subject?->getKey(),
            'causer_type' => $this->causer?->getMorphClass(),
            'causer_id' => $this->causer?->getKey(),
            'event' => $this->event,
            'properties' => $properties,
            'batch_uuid' => $this->batchUuid ?: null,
        ]);

        $this->resetState();

        return $activity;
    }

    protected function resetState(): void
    {
        $this->subject = null;
        $this->causer = null;
        $this->event = null;
        $this->properties = [];
        $this->logName = $this->defaultLogName;
        $this->batchUuid = null;
    }
}

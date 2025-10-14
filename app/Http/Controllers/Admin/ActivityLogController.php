<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Localization\DateFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('system.acp.view'), 403);

        $perPage = min(100, max(5, (int) $request->query('per_page', 25)));
        $search = trim((string) $request->query('search', ''));
        $event = trim((string) $request->query('event', '')) ?: null;
        $logName = trim((string) $request->query('log', '')) ?: null;
        $causerId = $request->query('causer_id');

        $query = Activity::query()->orderByDesc('created_at');

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('description', 'like', "%{$search}%")
                    ->orWhere('properties', 'like', "%{$search}%");
            });
        }

        if ($event) {
            $query->where('event', $event);
        }

        if ($logName) {
            $query->where('log_name', $logName);
        }

        if ($causerId !== null && $causerId !== '') {
            $query
                ->where('causer_type', User::class)
                ->where('causer_id', (int) $causerId);
        }

        $activities = $query
            ->with(['causer:id,nickname,email', 'subject'])
            ->paginate($perPage)
            ->withQueryString();

        $formatter = DateFormatter::for($request->user());

        $items = $activities->getCollection()
            ->map(function (Activity $activity) use ($formatter) {
                return [
                    'id' => $activity->id,
                    'description' => $activity->description,
                    'event' => $activity->event,
                    'log_name' => $activity->log_name,
                    'causer' => $activity->causer ? [
                        'id' => $activity->causer->id,
                        'nickname' => $activity->causer->nickname,
                        'email' => $activity->causer->email,
                    ] : null,
                    'subject' => $this->formatSubject($activity->subject),
                    'properties' => $this->formatProperties($activity->properties ?? []),
                    'created_at' => $formatter->iso($activity->created_at),
                    'time' => $formatter->human($activity->created_at),
                ];
            })
            ->values()
            ->all();

        $activityCollection = $activities->toArray();
        $activityCollection['data'] = $items;

        return Inertia::render('acp/AuditLog', [
            'activities' => $activityCollection,
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'event' => $event,
                'log' => $logName,
                'causer_id' => $causerId !== null ? (string) $causerId : null,
            ],
            'events' => $this->availableEvents(),
            'logs' => $this->availableLogs(),
            'actors' => $this->availableActors(),
        ]);
    }

    /**
     * @param mixed $subject
     */
    protected function formatSubject($subject): ?array
    {
        if (! $subject) {
            return null;
        }

        $label = null;

        if (is_object($subject)) {
            $attributes = method_exists($subject, 'getAttributes')
                ? $subject->getAttributes()
                : (array) $subject;

            $label = Arr::get($attributes, 'title')
                ?? Arr::get($attributes, 'name')
                ?? Arr::get($attributes, 'subject');
        }

        return [
            'type' => class_basename($subject),
            'id' => method_exists($subject, 'getKey') ? $subject->getKey() : null,
            'label' => $label,
        ];
    }

    protected function formatProperties(array $properties): array
    {
        $normalised = [];

        foreach ($properties as $key => $value) {
            if (is_array($value)) {
                $normalised[$key] = $this->formatProperties($value);
            } elseif ($value instanceof \DateTimeInterface) {
                $normalised[$key] = $value->format(\DateTimeInterface::ATOM);
            } else {
                $normalised[$key] = $value;
            }
        }

        return $normalised;
    }

    protected function availableEvents(): array
    {
        $configured = config('activitylog.displayable_events', []);

        $events = Activity::query()
            ->select('event')
            ->whereNotNull('event')
            ->distinct()
            ->orderBy('event')
            ->pluck('event')
            ->filter()
            ->values();

        return $events
            ->map(function (string $event) use ($configured) {
                return [
                    'value' => $event,
                    'label' => $configured[$event] ?? Str::headline(str_replace('.', ' ', $event)),
                ];
            })
            ->all();
    }

    protected function availableLogs(): array
    {
        return Activity::query()
            ->select('log_name')
            ->whereNotNull('log_name')
            ->distinct()
            ->orderBy('log_name')
            ->pluck('log_name')
            ->filter()
            ->values()
            ->map(fn (string $log) => ['value' => $log, 'label' => Str::title(str_replace('_', ' ', $log))])
            ->all();
    }

    protected function availableActors(): array
    {
        $actorIds = Activity::query()
            ->where('causer_type', User::class)
            ->whereNotNull('causer_id')
            ->orderByDesc('created_at')
            ->limit(50)
            ->pluck('causer_id')
            ->unique()
            ->values();

        if ($actorIds->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('id', $actorIds)
            ->get(['id', 'nickname', 'email'])
            ->map(fn (User $user) => [
                'value' => (string) $user->id,
                'label' => $user->nickname ?? $user->email ?? "User {$user->id}",
            ])
            ->sortBy('label')
            ->values()
            ->all();
    }
}

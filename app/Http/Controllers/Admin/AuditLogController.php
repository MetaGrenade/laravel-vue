<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Localization\DateFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class AuditLogController extends Controller
{
    use InteractsWithInertiaPagination;

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('audits.acp.view'), 403);

        $perPage = (int) $request->integer('per_page', 25);
        $perPage = min(max($perPage, 10), 100);

        $eventFilter = trim((string) $request->query('event', ''));
        $search = trim((string) $request->query('search', ''));
        $causerFilter = (int) $request->query('causer', 0);

        $query = Activity::query()
            ->with(['causer' => function ($relation) {
                $relation->select('id', 'nickname', 'email');
            }, 'subject'])
            ->where('log_name', config('activitylog.default_log_name', 'audit'))
            ->latest('created_at');

        if ($eventFilter !== '') {
            $query->where('event', $eventFilter);
        }

        if ($causerFilter > 0) {
            $query->where('causer_id', $causerFilter);
        }

        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('description', 'like', "%{$search}%")
                    ->orWhere('properties', 'like', "%{$search}%");
            });
        }

        $logs = $query->paginate($perPage)->withQueryString();

        $formatter = DateFormatter::for($request->user());

        $items = $logs->getCollection()->map(function (Activity $activity) use ($formatter) {
            $causer = $activity->causer;
            $subject = $activity->subject;

            return [
                'id' => $activity->id,
                'event' => $activity->event,
                'description' => $activity->description,
                'properties' => $activity->properties ?? [],
                'created_at' => $formatter->iso($activity->created_at),
                'created_at_for_humans' => $formatter->human($activity->created_at),
                'causer' => $this->formatCauser($causer),
                'subject' => $this->formatSubject($subject),
            ];
        })->values()->all();

        $eventOptions = Activity::query()
            ->where('log_name', config('activitylog.default_log_name', 'audit'))
            ->whereNotNull('event')
            ->distinct()
            ->orderBy('event')
            ->pluck('event')
            ->values()
            ->all();

        $actorIds = Activity::query()
            ->where('log_name', config('activitylog.default_log_name', 'audit'))
            ->whereNotNull('causer_id')
            ->distinct()
            ->pluck('causer_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $actors = User::query()
            ->whereIn('id', $actorIds)
            ->orderBy('nickname')
            ->get(['id', 'nickname', 'email'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'nickname' => $user->nickname,
                'email' => $user->email,
            ])
            ->values()
            ->all();

        return inertia('acp/AuditLogs', [
            'logs' => array_merge([
                'data' => $items,
            ], $this->inertiaPagination($logs)),
            'filters' => [
                'event' => $eventFilter !== '' ? $eventFilter : null,
                'search' => $search !== '' ? $search : null,
                'causer' => $causerFilter > 0 ? $causerFilter : null,
                'per_page' => $perPage,
            ],
            'events' => $eventOptions,
            'actors' => $actors,
        ]);
    }

    private function formatCauser(?Model $causer): ?array
    {
        if (! $causer instanceof User) {
            return null;
        }

        return [
            'id' => $causer->id,
            'nickname' => $causer->nickname,
            'email' => $causer->email,
        ];
    }

    private function formatSubject(?Model $subject): ?array
    {
        if (! $subject instanceof Model) {
            return null;
        }

        $label = null;

        foreach (['title', 'name', 'nickname', 'email'] as $attribute) {
            if (isset($subject->{$attribute}) && is_string($subject->{$attribute})) {
                $label = $subject->{$attribute};
                break;
            }
        }

        return [
            'id' => $subject->getKey(),
            'type' => class_basename($subject),
            'label' => $label,
        ];
    }
}


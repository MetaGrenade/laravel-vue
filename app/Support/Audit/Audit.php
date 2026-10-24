<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Stringable;
use Throwable;

/**
 * The admin audit log (see docs/audit-log.md): one entry for each change somebody makes in the admin area.
 *
 * `AuditAdminActions` opens an entry when such a request starts (`begin()`) and writes it when it ends (`finish()`). While it is open,
 * the models listed in `config/audit.php` (through `AuditObserver`) and permission changes (through `RecordPermissionChanges`) add what
 * they changed to it, so the entry says what a request did and not only that it was made. Nothing is written for a change made when
 * no entry is open, so people signing up, webhooks and queued jobs are not "admin actions".
 *
 * It is bound once for each request (`scoped`), but is reset by `begin()` and `finish()` as well, because a test, a queue worker or
 * Octane serves several in one process.
 */
class Audit
{
    private bool $recording = false;

    /** @var array{id: int|string, label: string}|null */
    private ?array $actor = null;

    private ?string $event = null;

    private ?Model $subject = null;

    /** @var list<array<string, mixed>> */
    private array $changes = [];

    private int $dropped = 0;

    /** @var array<string, mixed> */
    private array $meta = [];

    // --- During a request -----------------------------------------------------------------------------------------------------------

    /**
     * Open an entry for the request that is starting, for whoever is signed in (nobody: no entry will be written).
     */
    public function begin(?Authenticatable $actor): void
    {
        $this->reset();

        $this->recording = true;
        $this->actor = $actor instanceof User ? ['id' => $actor->getKey(), 'label' => self::actorLabel($actor)] : null;
    }

    public function recording(): bool
    {
        return $this->recording;
    }

    /**
     * Name what is being done, if the route's name will not do (`users.ban`, not `acp.users.ban`).
     */
    public function event(string $event): void
    {
        if ($this->recording) {
            $this->event = $event;
        }
    }

    /**
     * Say what it is being done to, if it is not the first record the address names.
     */
    public function subject(Model $model): void
    {
        if ($this->recording) {
            $this->subject = $model;
        }
    }

    /**
     * Add something to the entry's `meta` that the request does not show for itself.
     */
    public function note(string $key, mixed $value): void
    {
        if ($this->recording) {
            $this->meta[$key] = $value;
        }
    }

    /**
     * A record was made, changed or deleted while the entry is open.
     *
     * @param  'created'|'updated'|'deleted'  $action
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function changed(Model $model, string $action, array $old, array $new): void
    {
        $this->add([
            'model' => class_basename($model),
            'id' => $model->getKey(),
            'label' => self::labelFor($model),
            'action' => $action,
            'old' => $old,
            'new' => $new,
        ], $model);
    }

    /**
     * Roles or permissions were given to a record, or taken from it, while the entry is open.
     *
     * @param  'roles.attached'|'roles.detached'|'permissions.attached'|'permissions.detached'  $action
     * @param  list<string>  $names
     */
    public function attached(Model $model, string $action, array $names): void
    {
        $this->add([
            'model' => class_basename($model),
            'id' => $model->getKey(),
            'label' => self::labelFor($model),
            'action' => $action,
            'values' => $names,
        ], $model);
    }

    /**
     * Change the roles or permissions a record has (`syncRoles`, `syncPermissions`, `assignRole`...), and record what that really
     * changed: the names that were added and the names that were taken away, not what was asked for (asking for what a person
     * already has changes nothing). Anything that changes roles or permissions in the admin area goes through here.
     *
     * @param  'roles'|'permissions'  $relation
     * @param  callable(): mixed  $change
     */
    public function tracking(Model $model, string $relation, callable $change): mixed
    {
        if (! $this->recording) {
            return $change();
        }

        $before = $this->namesOf($model, $relation);
        $result = $change();
        $after = $this->namesOf($model, $relation);

        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added !== []) {
            $this->attached($model, "{$relation}.attached", $added);
        }

        if ($removed !== []) {
            $this->attached($model, "{$relation}.detached", $removed);
        }

        return $result;
    }

    /**
     * Write the entry for the request that has ended, and close it. Returns it, or null when nobody was signed in or it could not be
     * written (which is reported, and does not fail a request that has already done what it did).
     */
    public function finish(Request $request, int $status): ?AuditLog
    {
        try {
            if (! $this->recording || $this->actor === null) {
                return null;
            }

            $route = $request->route();
            $models = [];

            foreach ((array) $route?->parameters() as $name => $parameter) {
                if ($parameter instanceof Model) {
                    $models[(string) $name] = $parameter->getKey();
                }
            }

            $subject = $this->subject ?? collect((array) $route?->parameters())->first(fn ($parameter) => $parameter instanceof Model);
            $subject = $subject instanceof Model ? $subject : null;

            // Whose account it was about: the subject's, or if the address names no record, that of the first record that changed.
            $owner = ($subject !== null ? self::ownerOf($subject) : null) ?? ($this->changes[0]['owner'] ?? null);

            $meta = [
                'route' => $route?->getName(),
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'status' => $status,
                'fields' => array_slice(array_map('strval', array_keys($request->except(['_token', '_method']))), 0, 50),
            ];

            if ($models !== []) {
                $meta['models'] = $models;
            }

            if ($this->dropped > 0) {
                $meta['changes_not_kept'] = $this->dropped;
            }

            return $this->write(
                event: $this->event ?? self::eventFor($request),
                outcome: $this->outcomeOf($request, $status),
                actor: $this->actor,
                subject: $subject,
                owner: $owner,
                changes: array_map(fn (array $change) => array_diff_key($change, ['owner' => true]), $this->changes),
                meta: $meta + $this->meta,
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        } finally {
            $this->reset();
        }
    }

    // --- Writing an entry outside a request -----------------------------------------------------------------------------------------

    /**
     * Write an entry for something that did not come in as an admin request (a console command, a job), or that is not a change
     * (looking at a person's account as them).
     *
     * @param  list<array<string, mixed>>  $changes
     * @param  array<string, mixed>  $meta
     */
    public function record(string $event, ?Authenticatable $actor, ?Model $subject = null, array $changes = [], array $meta = [], string $outcome = AuditLog::SUCCEEDED, ?Request $request = null): AuditLog
    {
        return $this->write(
            event: $event,
            outcome: $outcome,
            actor: $actor instanceof User ? ['id' => $actor->getKey(), 'label' => self::actorLabel($actor)] : null,
            subject: $subject,
            owner: $subject !== null ? self::ownerOf($subject) : null,
            changes: $changes,
            meta: $meta,
            ip: $request?->ip(),
            userAgent: $request?->userAgent(),
        );
    }

    // --- What things are called -----------------------------------------------------------------------------------------------------

    /**
     * A person as the log names them: their nickname and address, as they are now (they may be renamed or deleted later).
     */
    public static function actorLabel(User $user): string
    {
        return Str::limit($user->nickname.' ('.$user->email.')', 255, '');
    }

    /**
     * What a record is called in the log, as it is now.
     */
    public static function labelFor(Model $model): string
    {
        $configured = config('audit.models.'.$model::class.'.label');
        $candidates = array_filter([$configured, 'nickname', 'name', 'title', 'code', 'key', 'label', 'public_id', 'slug', 'email']);

        foreach ($candidates as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_scalar($value) && (string) $value !== '') {
                return Str::limit((string) $value, 255, '');
            }
        }

        return class_basename($model).' #'.$model->getKey();
    }

    /**
     * The account a record belongs to, as a type and an id: the person themselves, or whoever owns it.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function ownerOf(Model $model): ?array
    {
        if ($model instanceof User) {
            return [User::class, (int) $model->getKey()];
        }

        // Owned through an `owner` morph (an order), or through a `tokenable` one (an API token).
        foreach (['owner', 'tokenable'] as $morph) {
            $type = $model->getAttribute("{$morph}_type");
            $id = $model->getAttribute("{$morph}_id");

            if (is_string($type) && $type !== '' && is_numeric($id)) {
                return [$type, (int) $id];
            }
        }

        $userId = $model->getAttribute('user_id');

        return is_numeric($userId) ? [User::class, (int) $userId] : null;
    }

    /**
     * What a request did, as the log names it: the route's name without `acp.` (`acp.users.ban` is `users.ban`).
     */
    public static function eventFor(Request $request): string
    {
        $name = $request->route()?->getName();

        if (is_string($name) && $name !== '') {
            return Str::limit(Str::after($name, 'acp.'), 120, '');
        }

        return Str::limit(strtolower($request->method()).' '.$request->path(), 120, '');
    }

    /**
     * What an action is called when people read the log: its name from `config/audit.php`, or else its route's name as words.
     */
    public static function describe(string $event): string
    {
        // The whole map, indexed: an event's name has dots in it, which `config('audit.events.users.ban')` would read as nesting.
        $named = ((array) config('audit.events', []))[$event] ?? null;

        return is_string($named) && $named !== '' ? $named : ucfirst(str_replace(['.', '-', '_'], ' ', $event));
    }

    /**
     * A value as it is kept in the log: short, and plain text or a plain number.
     */
    public static function scalar(mixed $value): mixed
    {
        $max = (int) config('audit.max_value_length', 300);

        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof BackedEnum => $value->value,
            is_string($value) => Str::limit($value, $max, '…'),
            is_array($value) => Str::limit((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $max, '…'),
            $value instanceof Stringable => Str::limit((string) $value, $max, '…'),
            default => '['.get_debug_type($value).']',
        };
    }

    // --- Inside -----------------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $change
     */
    private function add(array $change, Model $model): void
    {
        if (! $this->recording) {
            return;
        }

        if (count($this->changes) >= (int) config('audit.max_changes', 100)) {
            $this->dropped++;

            return;
        }

        $owner = self::ownerOf($model);

        // Kept beside the change until the entry is written, to say whose account the request was about when it names no record.
        $this->changes[] = $change + ['owner' => $owner];
    }

    /**
     * @return list<string>
     */
    private function namesOf(Model $model, string $relation): array
    {
        // Asked of the database each time: the relation a model has loaded is what it was before.
        $names = $model->{$relation}()->pluck('name')->map(fn ($name) => (string) $name)->all();
        sort($names);

        return $names;
    }

    private function outcomeOf(Request $request, int $status): string
    {
        if ($status === 403) {
            return AuditLog::DENIED;
        }

        if ($status >= 400) {
            return AuditLog::FAILED;
        }

        // A redirect back with validation errors, or with an error to read, is a request that did not do what it asked. Only what was
        // flashed by this request counts: what the one before flashed can still be read, and says nothing about this one.
        if ($request->hasSession()) {
            $flashedNow = (array) $request->session()->get('_flash.new', []);
            $errors = $request->session()->get('errors');

            if (in_array('error', $flashedNow, true) || (in_array('errors', $flashedNow, true) && $errors instanceof ViewErrorBag && $errors->any())) {
                return AuditLog::FAILED;
            }
        }

        return AuditLog::SUCCEEDED;
    }

    /**
     * @param  array{id: int|string, label: string}|null  $actor
     * @param  array{0: string, 1: int}|null  $owner
     * @param  list<array<string, mixed>>  $changes
     * @param  array<string, mixed>  $meta
     */
    private function write(string $event, string $outcome, ?array $actor, ?Model $subject, ?array $owner, array $changes, array $meta, ?string $ip, ?string $userAgent): AuditLog
    {
        return AuditLog::query()->create([
            'actor_id' => $actor['id'] ?? null,
            'actor_label' => $actor['label'] ?? null,
            'event' => Str::limit($event, 120, ''),
            'outcome' => $outcome,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject !== null && is_numeric($subject->getKey()) ? (int) $subject->getKey() : null,
            'subject_label' => $subject !== null ? self::labelFor($subject) : null,
            'owner_type' => $owner[0] ?? null,
            'owner_id' => $owner[1] ?? null,
            'changes' => $changes === [] ? null : $changes,
            'meta' => $meta === [] ? null : $meta,
            'ip_address' => $ip,
            'user_agent' => $userAgent !== null ? Str::limit($userAgent, 500, '') : null,
        ]);
    }

    private function reset(): void
    {
        $this->recording = false;
        $this->actor = null;
        $this->event = null;
        $this->subject = null;
        $this->changes = [];
        $this->dropped = 0;
        $this->meta = [];
    }
}

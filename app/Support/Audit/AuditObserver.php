<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Adds what a model's changes were to the audit entry that is open (see `Audit`), for each model listed in `config/audit.php`.
 *
 * It does nothing when no entry is open, so the same change made by a customer, a webhook or a job is not recorded.
 */
class AuditObserver
{
    public function created(Model $model): void
    {
        $audit = app(Audit::class);

        if ($audit->recording()) {
            $audit->changed($model, 'created', [], $this->shown($model, array_keys($model->getAttributes()), false));
        }
    }

    public function updated(Model $model): void
    {
        $audit = app(Audit::class);

        if (! $audit->recording()) {
            return;
        }

        $new = $this->shown($model, array_keys($model->getChanges()), false);

        // Only what was ignored changed (a timestamp, the last time somebody was seen): nothing to say.
        if ($new === []) {
            return;
        }

        $audit->changed($model, 'updated', $this->shown($model, array_keys($new), true), $new);
    }

    public function deleted(Model $model): void
    {
        $audit = app(Audit::class);

        if ($audit->recording()) {
            $audit->changed($model, 'deleted', $this->shown($model, array_keys($model->getAttributes()), false), []);
        }
    }

    /**
     * The attributes as the log keeps them: without the ones that are not worth an entry, with secrets hidden, and as the model reads
     * them (a yes or no as a yes or no, not 1), so what a thing was and what it became can be compared.
     *
     * @param  list<int|string>  $names
     * @param  bool  $original  The value it had before it was changed, not the one it has.
     * @return array<string, mixed>
     */
    private function shown(Model $model, array $names, bool $original): array
    {
        $ignored = array_merge(['created_at', 'updated_at'], (array) config('audit.models.'.$model::class.'.ignore', []));
        $shown = [];

        foreach ($names as $name) {
            $name = (string) $name;

            if (in_array($name, $ignored, true)) {
                continue;
            }

            $shown[$name] = match (true) {
                $this->isSecret($model, $name) => '[hidden]',
                $original => Audit::scalar($model->getOriginal($name)),
                default => Audit::scalar($model->getAttribute($name)),
            };
        }

        return $shown;
    }

    private function isSecret(Model $model, string $attribute): bool
    {
        if (in_array($attribute, (array) config('audit.models.'.$model::class.'.hide', []), true) || in_array($attribute, $model->getHidden(), true)) {
            return true;
        }

        // `token` and anything ending `_token`, but not `tokenable_id`: that says whose token it is.
        if ($attribute === 'token' || str_ends_with($attribute, '_token')) {
            return true;
        }

        foreach ((array) config('audit.hide_containing', []) as $needle) {
            if (str_contains(strtolower($attribute), (string) $needle)) {
                return true;
            }
        }

        return false;
    }
}

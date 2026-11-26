<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Reflector;
use Illuminate\Support\Str;
use ReflectionNamedType;
use ReflectionParameter;
use Symfony\Component\HttpFoundation\Response;

/**
 * An address with something other than a number where a record's number goes (`/acp/users/abc/edit`), or with a number longer than a
 * column holds, names nothing: it is "not found".
 *
 * Left to go on, many of these are a server error. PostgreSQL refuses to compare a number column with such text, and a controller that
 * asks for an `int` cannot be called with a number that is too big for one, whatever the database. SQLite, MySQL and MariaDB find
 * nothing, or, for text that starts with digits (`5abc`), the last two find record 5.
 *
 * It is told what is a number by the route's controller: a parameter it asks for as an `int`, and a record it is given that is found
 * by a column the model casts to a whole number (its key, or the number another service gave it). A route that names a column says so
 * in its address (`{package:tebex_id}`).
 *
 * It runs right before the records are looked up (bootstrap/app.php), after the sign-in check, the usage meter, a key's scopes and the
 * audit log, so each of them treats the request as it treats one for a record that is not there.
 */
class RefuseImpossibleIds
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $values = $route->parameters();

        foreach ($values === [] ? [] : $route->signatureParameters() as $parameter) {
            // As Laravel pairs them: `$roleMapping` takes `{roleMapping}`, or `{role_mapping}`.
            $name = array_key_exists($parameter->getName(), $values) ? $parameter->getName() : Str::snake($parameter->getName());
            $value = $values[$name] ?? null;

            if (! is_string($value) || self::couldBeAnId($value)) {
                continue;
            }

            $model = $this->recordFoundByNumber($parameter, $route->bindingFieldFor($name));

            if ($model !== null) {
                // What Laravel throws for a record that is not there, so the answer is the same.
                throw (new ModelNotFoundException)->setModel($model, [$value]);
            }

            $type = $parameter->getType();

            abort_if($type instanceof ReflectionNamedType && $type->getName() === 'int', 404);
        }

        return $next($request);
    }

    /** Digits, and no more of them than a database's biggest number (and PHP's) has. Leading zeros do not change a number. */
    private static function couldBeAnId(string $value): bool
    {
        return ctype_digit($value) && filter_var(ltrim($value, '0') ?: '0', FILTER_VALIDATE_INT) !== false;
    }

    /**
     * The model the parameter is given, when it is looked up by a number.
     *
     * @return class-string<Model>|null
     */
    private function recordFoundByNumber(ReflectionParameter $parameter, ?string $column): ?string
    {
        $class = Reflector::getParameterClassName($parameter);

        if ($class === null || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        $model = new $class;

        return $model->hasCast($column ?? $model->getRouteKeyName(), ['int', 'integer']) ? $class : null;
    }
}

<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * Run the rules of a form request on one value, with no request to send: what a form says about a value for one field.
 */
trait ChecksFormRules
{
    /**
     * @param  class-string<FormRequest>  $class
     * @param  array<string, object>  $route  The route parameters the rules read (`['user' => (object) ['id' => 1]]`).
     * @return Collection<int, string> The messages for that field; empty when the value is accepted.
     */
    private function errorsFor(string $class, array $route, string $field, mixed $value): Collection
    {
        /** @var FormRequest $request */
        $request = new $class;
        $request->setUserResolver(fn () => User::factory()->make(['id' => 1]));
        $request->setRouteResolver(fn () => new class($route)
        {
            /** @param  array<string, object>  $parameters */
            public function __construct(private array $parameters) {}

            public function parameter(string $name, mixed $default = null): mixed
            {
                return $this->parameters[$name] ?? $default;
            }
        });

        $data = [];
        Arr::set($data, $field, $value);

        $validator = Validator::make($data, $request->rules());
        $validator->fails();

        return collect($validator->errors()->get($field));
    }
}

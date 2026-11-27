<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\Permission\PermissionRegistrar;

/**
 * For the name of a role or a permission. Spatie takes a string shaped like a UUID or a ULID for a record's id, and a database may
 * make a number of such a string: giving a role of that name could give the role with that number, and the first one is the
 * administrator's.
 */
final class NotShapedLikeAnId implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (PermissionRegistrar::isUid($value)) {
            $fail('The :attribute cannot have the shape of a UUID or a ULID, which is read as a record number.');
        }
    }
}

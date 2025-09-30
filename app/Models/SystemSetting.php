<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Cast the raw JSON value to native PHP types when accessing.
     */
    protected function getValueAttribute($value): mixed
    {
        return json_decode($value, true);
    }

    /**
     * Ensure the value is stored as JSON.
     */
    protected function setValueAttribute($value): void
    {
        $this->attributes['value'] = json_encode($value);
    }

    /**
     * Retrieve a setting value with a fallback default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->first()?->value ?? $default;
    }

    /**
     * Persist a setting value.
     */
    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}

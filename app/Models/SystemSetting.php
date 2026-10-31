<?php

namespace App\Models;

use App\Support\Settings\SettingsSnapshot;
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
     * Whatever is saved or deleted through the model, the settings read so far in this request are read again.
     */
    protected static function booted(): void
    {
        $forget = static fn () => app(SettingsSnapshot::class)->forget();

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * Retrieve a setting value with a fallback default. All the settings are read with one query the first time a request asks for
     * one (see SettingsSnapshot), not one query for each.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return app(SettingsSnapshot::class)->get($key, $default);
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserNotificationSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category',
        'channel_mail',
        'channel_push',
        'channel_database',
    ];

    protected $casts = [
        'channel_mail' => 'boolean',
        'channel_push' => 'boolean',
        'channel_database' => 'boolean',
    ];

    public function isChannelEnabled(string $channel): bool
    {
        return match ($channel) {
            'mail' => (bool) $this->channel_mail,
            'push' => (bool) $this->channel_push,
            'database' => (bool) $this->channel_database,
            default => false,
        };
    }
}

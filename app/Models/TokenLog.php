<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'personal_access_token_id',
        'token_name',
        'route',
        'method',
        'status',
        'http_status',
        'ip_address',
        'user_agent',
        'request_payload',
        'response_summary',
        'response_time_ms',
        'error_message',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_summary' => 'array',
    ];

    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }
}

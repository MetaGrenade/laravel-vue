<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'provider_id',
        'name',
        'nickname',
        'email',
        'avatar',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'data',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'token_expires_at' => 'datetime',
        ];
    }

    /**
     * Get the owning user of the social account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable, HasRoles, HasApiTokens, MustVerifyEmailTrait;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'nickname',
        'email',
        'password',
        'email_verified_at',
        'avatar_url',
        'forum_signature',
        'profile_bio',
        'social_links',
        'is_banned',
        'last_activity_at',
        'banned_at',
        'banned_by_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_banned' => 'boolean',
            'last_activity_at' => 'datetime',
            'banned_at' => 'datetime',
            'social_links' => 'array',
        ];
    }

    public function forumThreads(): HasMany
    {
        return $this->hasMany(ForumThread::class);
    }

    public function forumPosts(): HasMany
    {
        return $this->hasMany(ForumPost::class);
    }

    public function forumThreadReads(): HasMany
    {
        return $this->hasMany(ForumThreadRead::class);
    }

    public function blogComments(): HasMany
    {
        return $this->hasMany(BlogComment::class);
    }

    public function bannedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'banned_by_id');
    }
}


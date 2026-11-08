<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sign-in service (Google, Discord, Steam) a member has linked: who they are there, and what the service told us about them.
 *
 * It keeps no token. The provider's access and refresh tokens are used once, to ask who the person is, and are dropped with the request;
 * nothing in the site needs them later, and a token in a database backup is a key to somebody's account at the provider. See
 * docs/social-login.md ("What is kept from a provider").
 */
class SocialAccount extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
        'name',
        'nickname',
        'email',
        'avatar',
    ];

    /**
     * Get the owning user of the social account.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

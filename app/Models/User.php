<?php

namespace App\Models;

use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        'reputation_points',
        'profile_bio',
        'social_links',
        'timezone',
        'locale',
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
        'two_factor_secret',
        'two_factor_recovery_codes',
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
            'two_factor_confirmed_at' => 'datetime',
            'reputation_points' => 'integer',
        ];
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if (! is_string($value) || $value === '') {
                    return null;
                }

                if (Str::startsWith($value, ['http://', 'https://', '//', 'data:'])) {
                    return $value;
                }

                $normalized = ltrim($value, '/');

                if ($normalized === '') {
                    return null;
                }

                if (Str::startsWith($normalized, 'storage/')) {
                    return '/' . $normalized;
                }

                return Storage::disk('public')->url($normalized);
            },
        );
    }

    public function avatarStoragePath(): ?string
    {
        $raw = $this->getRawOriginal('avatar_url');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $normalized = ltrim($raw, '/');

        if ($normalized === '') {
            return null;
        }

        if (Str::startsWith($normalized, ['http://', 'https://', '//', 'data:'])) {
            return null;
        }

        if (Str::startsWith($normalized, 'storage/')) {
            $normalized = Str::after($normalized, 'storage/');
        }

        return $normalized;
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

    public function forumThreadSubscriptions(): HasMany
    {
        return $this->hasMany(ForumThreadSubscription::class);
    }

    public function reputationEvents(): HasMany
    {
        return $this->hasMany(ReputationEvent::class);
    }

    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class)
            ->withPivot('awarded_at')
            ->withTimestamps();
    }

    public function subscribedForumThreads(): BelongsToMany
    {
        return $this->belongsToMany(ForumThread::class, 'forum_thread_subscriptions')->withTimestamps();
    }

    public function subscribedBlogComments(): BelongsToMany
    {
        return $this->belongsToMany(Blog::class, 'blog_comment_subscriptions')
            ->withTimestamps();
    }

    public function blogComments(): HasMany
    {
        return $this->hasMany(BlogComment::class);
    }

    public function bannedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'banned_by_id');
    }

    public function supportTeams(): BelongsToMany
    {
        return $this->belongsToMany(SupportTeam::class, 'support_team_user')
            ->withTimestamps();
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function dataExports(): HasMany
    {
        return $this->hasMany(DataExport::class);
    }

    public function dataErasureRequests(): HasMany
    {
        return $this->hasMany(DataErasureRequest::class);
    }

    public function notificationSettings(): HasMany
    {
        return $this->hasMany(UserNotificationSetting::class);
    }

    /**
     * @param  list<string>|null  $candidateChannels
     * @return list<string>
     */
    public function preferredNotificationChannelsFor(string $category, ?array $candidateChannels = null): array
    {
        $this->loadMissing('notificationSettings');

        $categoryConfig = (array) config('notification-preferences.categories', []);
        $channelConfig = (array) config('notification-preferences.channels', []);

        $allowedChannels = $candidateChannels
            ?? ($categoryConfig[$category]['channels'] ?? array_keys($channelConfig));

        if ($allowedChannels === []) {
            return [];
        }

        $settings = $this->notificationSettings->firstWhere('category', $category);

        $channels = [];

        foreach ($allowedChannels as $channel) {
            if (! isset($channelConfig[$channel])) {
                continue;
            }

            $defaultEnabled = (bool) ($channelConfig[$channel]['default'] ?? true);
            $enabled = $settings ? $settings->isChannelEnabled($channel) : $defaultEnabled;

            if (! $enabled) {
                continue;
            }

            if ($channel === 'mail' && ! $this->hasVerifiedEmail()) {
                continue;
            }

            $channels[] = $channel;
        }

        return array_values(array_unique($channels));
    }

    /**
     * Send the given notification using the recipient's preferred channels for the category.
     *
     * @param  array<int, string>|null  $candidateChannels
     */
    public function notifyThroughPreferences(BaseNotification $notification, string $category, ?array $candidateChannels = null): void
    {
        $channels = $this->preferredNotificationChannelsFor($category, $candidateChannels);

        if ($channels === []) {
            return;
        }

        $synchronousChannels = array_values(array_intersect($channels, ['database']));
        $queuedChannels = array_values(array_diff($channels, $synchronousChannels));

        if ($synchronousChannels !== []) {
            NotificationFacade::sendNow(
                $this,
                $this->cloneNotificationWithChannels($notification, $synchronousChannels)
            );
        }

        if ($queuedChannels !== []) {
            NotificationFacade::send(
                $this,
                $this->cloneNotificationWithChannels($notification, $queuedChannels)
            );
        }
    }

    /**
     * @param  array<int, string>  $channels
     */
    protected function cloneNotificationWithChannels(BaseNotification $notification, array $channels): BaseNotification
    {
        $instance = clone $notification;

        if (method_exists($instance, 'withChannels')) {
            return $instance->withChannels($channels);
        }

        if (property_exists($instance, 'channels')) {
            $instance->channels = $channels;
        }

        return $instance;
    }
}


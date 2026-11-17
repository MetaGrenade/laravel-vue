<?php

namespace App\Models;

use App\Notifications\EmailAddressChanged;
use App\Support\Entitlements\Entitlements;
use App\Support\Entitlements\EntitlementSet;
use App\Support\Forum\ForumAttachments;
use App\Support\Localization\Locales;
use App\Support\Notifications\EmailDigest;
use App\Support\Notifications\RecordsForDigest;
use App\Support\Notifications\SplitDelivery;
use App\Support\Privacy\MemberLeftovers;
use App\Support\Search\CascadedDocuments;
use App\Support\Search\DocumentsLeaving;
use Carbon\CarbonInterface;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property CarbonInterface|null $banned_at
 * @property CarbonInterface|null $banned_until
 * @property string|null $ban_reason
 * @property CarbonInterface|null $last_activity_at
 * @property array<array-key, mixed>|null $social_links
 */
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    use Billable, HasApiTokens, HasFactory, HasRoles, MustVerifyEmailTrait, Notifiable;

    /**
     * The files that go with this account, found by the `deleting` hook (while the rows that name them still exist) for the `deleted`
     * one to unlink. Not an attribute: it is never saved. See MemberLeftovers.
     *
     * @var list<array{disk: string, path: string}>
     */
    private array $filesToForget = [];

    /**
     * The search documents that go with this account (its threads and its blog posts) and the threads whose document holds one of its
     * replies, found by the `deleting` hook for the `deleted` one to ask the index about. Not an attribute: it is never saved.
     */
    private ?DocumentsLeaving $documentsLeaving = null;

    /**
     * The language of what is sent to this person, an email or a notice in the site, whoever caused it and whatever language that
     * person's own request was in. Laravel asks for it whenever a notification is sent, queued or not. The `locale` setting names
     * a language and a region ("fr_CA"); only the language is used, and only if the site has it, or else the site's own.
     */
    public function preferredLocale(): string
    {
        return Locales::supportedLanguage($this->locale) ?? Locales::default();
    }

    /**
     * Where an email to this person goes: the address of the account, except for the notice that the address was changed, which goes
     * to the one the account had, the only one that can still reach somebody who did not change it.
     */
    public function routeNotificationForMail(BaseNotification $notification): string
    {
        return $notification instanceof EmailAddressChanged ? $notification->oldEmail : (string) $this->email;
    }

    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            // The database then removes this account's posts, and every reply in threads they started, without
            // running the post or thread events that would delete those files.
            $ids = [];

            foreach (
                ForumPost::query()
                    ->withTrashed()
                    ->withoutGlobalScope(ForumPost::VISIBLE)
                    ->where(function (Builder $query) use ($user): void {
                        $query->where('user_id', $user->id)
                            ->orWhereIn(
                                'forum_thread_id',
                                ForumThread::query()->where('user_id', $user->id)->select('id'),
                            );
                    })
                    ->pluck('id') as $id
            ) {
                $ids[] = (int) $id;
            }

            app(ForumAttachments::class)->forget($ids);

            // The same for the zip of an export and the attachments of their tickets, which are unlinked once the account is gone.
            $user->filesToForget = app(MemberLeftovers::class)->filesOf($user);

            // And the search index, which is not told of a row the database removes: the threads and the blog posts they wrote go from it,
            // and a thread of somebody else that has one of their visible replies in its document is made again without it.
            $user->documentsLeaving = app(CascadedDocuments::class)->before(
                [
                    ForumThread::class => DB::table('forum_threads')->where('user_id', $user->id),
                    Blog::class => DB::table('blogs')->where('user_id', $user->id),
                ],
                fn (): array => ForumPost::query()
                    ->where('user_id', $user->id)
                    ->distinct()
                    ->pluck('forum_thread_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all(),
            );
        });
        static::saved(fn () => SupportAssignmentRule::flushCache());
        static::deleted(function (User $user): void {
            SupportAssignmentRule::flushCache();

            // The rows the database cannot cascade (they have no foreign key to the user) and the files found above.
            app(MemberLeftovers::class)->forget($user, $user->filesToForget);
            $user->filesToForget = [];

            app(CascadedDocuments::class)->after($user->documentsLeaving);
            $user->documentsLeaving = null;
        });
        static::registerModelEvent('forceDeleted', fn () => SupportAssignmentRule::flushCache());
    }

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
            'banned_until' => 'datetime',
            'social_links' => 'array',
            'two_factor_confirmed_at' => 'datetime',
            'reputation_points' => 'integer',
            'trial_ends_at' => 'datetime',
            'onboarding_dismissed_at' => 'datetime',
        ];
    }

    /**
     * The API keys the person has made: Sanctum's relation, but typed as the site's own model of a key.
     *
     * @return MorphMany<PersonalAccessToken, $this>
     */
    public function tokens(): MorphMany
    {
        return $this->morphMany(PersonalAccessToken::class, 'tokenable');
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

    /**
     * @return BelongsToMany<Badge, $this>
     */
    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Badge::class)
            ->withPivot('awarded_at')
            ->withTimestamps();
    }

    /**
     * Everything this user has been given by a purchase (or by staff): the features that are on and the limits, from every
     * owner they act for. Not a relation, so it is not called `entitlements`.
     */
    public function entitlementSet(): EntitlementSet
    {
        return app(Entitlements::class)->forUser($this);
    }

    /**
     * Whether the user holds an entitlement: a feature that is on, or a limit above nothing.
     */
    public function canUse(string $entitlement): bool
    {
        return $this->entitlementSet()->has($entitlement);
    }

    /**
     * How much of a limit the user has (0 for one they do not hold).
     */
    public function limitOf(string $entitlement): int
    {
        return $this->entitlementSet()->limit($entitlement);
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

    /**
     * What moderators have done to this member (docs/moderation.md).
     *
     * @return HasMany<ModerationAction, $this>
     */
    public function moderationActions(): HasMany
    {
        return $this->hasMany(ModerationAction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
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

    /**
     * @return HasMany<WishlistItem, $this>
     */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /**
     * @return HasMany<ProductReview, $this>
     */
    public function productReviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
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

    public function billingInvoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class);
    }

    public function pollVotes(): HasMany
    {
        return $this->hasMany(PollVote::class);
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
        $mailAdded = false;
        $broadcastAdded = false;

        foreach ($allowedChannels as $channel) {
            if (! isset($channelConfig[$channel])) {
                continue;
            }

            $defaultEnabled = (bool) ($channelConfig[$channel]['default'] ?? true);
            $enabled = $settings ? $settings->isChannelEnabled($channel) : $defaultEnabled;

            if (! $enabled) {
                continue;
            }

            if ($channel === 'mail') {
                if (! $this->hasVerifiedEmail()) {
                    continue;
                }

                $channels[] = 'mail';
                $mailAdded = true;

                continue;
            }

            if ($channel === 'push') {
                $channels[] = 'broadcast';
                $broadcastAdded = true;

                continue;
            }

            $channels[] = $channel;
        }

        return array_values(array_unique($channels));
    }

    public function wantsSupportTeamNotifications(): bool
    {
        $this->loadMissing('notificationSettings');

        $settings = $this->notificationSettings->firstWhere('category', 'support');

        if ($settings) {
            return $settings->isOptionEnabled('team_notifications');
        }

        $categoryConfig = (array) config('notification-preferences.categories', []);
        $optionConfig = (array) ($categoryConfig['support']['options']['team_notifications'] ?? []);

        return (bool) ($optionConfig['default'] ?? true);
    }

    /**
     * The member asked for one email a day for this area, instead of an email for each update (docs/email-digests.md).
     */
    public function wantsDailyDigest(string $category): bool
    {
        $option = config('notification-preferences.categories.'.$category.'.options.daily_digest');

        if (! is_array($option)) {
            return false;
        }

        $this->loadMissing('notificationSettings');

        $settings = $this->notificationSettings->firstWhere('category', $category);

        if (! $settings instanceof UserNotificationSetting) {
            return (bool) ($option['default'] ?? false);
        }

        return $settings->isOptionEnabled('daily_digest');
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

        // A daily digest keeps the email for the morning batch. The notice in the site, and the browser alert, still go now.
        if (in_array('mail', $channels, true) && $notification instanceof RecordsForDigest && $this->wantsDailyDigest($category) && app(EmailDigest::class)->hold($this, $category, $notification)) {
            $channels = array_values(array_diff($channels, ['mail']));
        }

        if ($channels === []) {
            return;
        }

        app(SplitDelivery::class)->send(
            $this,
            $channels,
            fn (array $part): BaseNotification => $this->cloneNotificationWithChannels($notification, $part),
        );
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

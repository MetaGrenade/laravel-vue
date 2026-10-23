<?php

namespace App\Providers;

use App\Models\Address;
use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\DownloadGrant;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\Order;
use App\Models\PersonalAccessToken;
use App\Observers\ForumIndexCacheObserver;
use App\Payments\PaymentManager;
use App\Payments\Stripe\CashierStripeGateway;
use App\Payments\Stripe\StripeGateway;
use App\Payments\Tebex\TebexStoreProfile;
use App\Policies\AddressPolicy;
use App\Policies\BlogCommentPolicy;
use App\Policies\BlogPolicy;
use App\Policies\ForumPostPolicy;
use App\Policies\OrderPolicy;
use App\Support\Billing\SubscriptionManager;
use App\Support\Security\HtmlSanitizer;
use App\Support\Seo\Seo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SubscriptionManager::class);
        $this->app->singleton(HtmlSanitizer::class);
        $this->app->singleton(PaymentManager::class);
        $this->app->singleton(TebexStoreProfile::class);
        $this->app->bind(StripeGateway::class, CashierStripeGateway::class);
        $this->app->scoped(Seo::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Only set MySQL’s time zone when you’re actually using MySQL
        // When GitHub Actions run there is no real .env file, so Laravel falls back to its default database connection
        // Which is set in config/database.php :19 'default' => env('DB_CONNECTION', 'sqlite'),
        if (DB::getDriverName() === 'mysql') {
            // force DB timestamps to use 'UTC' timezone for more accurate dayjs conversion to local timezones
            DB::statement("SET time_zone = '+00:00'");
        }

        Gate::policy(ForumPost::class, ForumPostPolicy::class);
        Gate::policy(Blog::class, BlogPolicy::class);
        Gate::policy(BlogComment::class, BlogCommentPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Address::class, AddressPolicy::class);
        Gate::policy(SupportTicketMessageAttachment::class, SupportTicketMessageAttachmentPolicy::class);

        Gate::before(function ($user) {
            return $user->hasRole('admin') ? true : null;
        });

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Sanctum turns a key away once it has expired but knows nothing of `revoked_at`, so without this a revoked key kept working.
        Sanctum::authenticateAccessTokensUsing(fn (PersonalAccessToken $key, bool $valid): bool => $valid && ! $key->isRevoked());

        $this->configureDefaults();
        $this->configureRateLimiting();

        RateLimiter::for('blog-comments', function ($request) {
            $attempts = (int) config('rate-limits.blog_comments_per_minute', 5);

            return Limit::perMinute($attempts)->by(optional($request->user())->id ?? $request->ip());
        });

        $cacheObserver = $this->app->make(ForumIndexCacheObserver::class);

        ForumCategory::observe($cacheObserver);
        ForumBoard::observe($cacheObserver);
        ForumThread::observe($cacheObserver);
        ForumPost::observe($cacheObserver);

    }

    /**
     * Safer defaults: stricter passwords and no destructive commands in
     * production, N+1 query warnings during local development, and asset
     * prefetching for faster client-side navigation.
     */
    protected function configureDefaults(): void
    {
        $production = $this->app->isProduction();

        DB::prohibitDestructiveCommands($production);

        Password::defaults(fn () => $production
            ? Password::min(12)->letters()->mixedCase()->numbers()->uncompromised()
            : Password::min(8));

        Model::preventLazyLoading($this->app->isLocal());
        Model::handleLazyLoadingViolationUsing(function ($model, string $relation) {
            Log::warning(sprintf('N+1 query: lazy loading [%s] on model [%s].', $relation, $model::class));
        });

        if (config('app.force_https')) {
            URL::forceHttps();
        }

        Vite::prefetch(concurrency: 3);
    }

    /**
     * Named rate limiters for web endpoints (API routes are throttled per token).
     */
    protected function configureRateLimiting(): void
    {
        $byUserOrIp = fn (Request $request) => $request->user()?->getAuthIdentifier() ?? $request->ip();

        // Account creation and password reset emails.
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by('auth:'.$request->ip().'|'.Str::lower((string) $request->input('email'))),
            Limit::perMinute(20)->by('auth-ip:'.$request->ip()),
        ]);

        // One-time codes must not be brute-forceable.
        RateLimiter::for('two-factor', fn (Request $request) => [
            Limit::perMinute(5)->by('2fa:'.$request->session()->get('two_factor:id', $request->ip())),
            Limit::perMinute(10)->by('2fa-ip:'.$request->ip()),
        ]);

        RateLimiter::for('confirm-password', fn (Request $request) => Limit::perMinute(5)->by('confirm:'.$byUserOrIp($request)));

        // Posting community content (threads, replies, tickets, messages).
        RateLimiter::for('content', fn (Request $request) => [
            Limit::perMinute(10)->by('content:'.$byUserOrIp($request)),
            Limit::perHour(120)->by('content-hourly:'.$byUserOrIp($request)),
        ]);

        // Reports, reactions, subscriptions and other lightweight interactions.
        RateLimiter::for('interactions', fn (Request $request) => Limit::perMinute(30)->by('interact:'.$byUserOrIp($request)));

        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(60)->by('search:'.$byUserOrIp($request)));

        RateLimiter::for('billing', fn (Request $request) => Limit::perMinute(10)->by('billing:'.$byUserOrIp($request)));

        // Making API keys: each one is a new credential, so a stolen session cannot be used to mint them by the hundred. A person
        // signed in is counted as themselves; one signing in over the API, as that address from that place (guessing passwords
        // there is the business of 'api-sign-in', and one address should not use up the allowance of everybody behind the same IP).
        // An email that is not text (a validation error anyway) must not break the limiter, which runs before validation does.
        RateLimiter::for('api-keys', function (Request $request) {
            $email = $request->input('email');
            $who = $request->user()?->getAuthIdentifier() ?? $request->ip().'|'.Str::lower(is_string($email) ? $email : '');

            return [
                Limit::perMinute(5)->by('api-keys:'.$who),
                Limit::perHour(20)->by('api-keys-hourly:'.$who),
            ];
        });

        // Signing in over the API: every request counts, right or wrong, so one place cannot try passwords against address after
        // address (the limit above is per address). It sits after 'api-keys' on the route, so a request that address has already
        // used up is turned away first and costs the place nothing.
        RateLimiter::for('api-sign-in', fn (Request $request) => Limit::perMinute(20)->by('api-sign-in-ip:'.$request->ip()));

        // Files are big: a purchased download is limited per order and address, not just per person.
        RateLimiter::for('downloads', function (Request $request) use ($byUserOrIp) {
            $grant = $request->route('grant');
            $grant = $grant instanceof DownloadGrant ? $grant->public_id : (string) $grant;

            return Limit::perMinute(20)->by('downloads:'.$byUserOrIp($request).'|'.$grant);
        });

        // Trying discount codes: every attempt counts, right or wrong, so a code cannot be guessed.
        RateLimiter::for('coupons', fn (Request $request) => [
            Limit::perMinute(10)->by('coupons:'.$byUserOrIp($request)),
            Limit::perHour(60)->by('coupons-hourly:'.$byUserOrIp($request)),
        ]);

        // Trying promotion codes on a subscription: every attempt counts, right or wrong, and each one is a question put to Stripe.
        // It is for any request that carries a code (checking one, or starting a subscription with one, which looks it up as well),
        // and they share the allowance, so a code cannot be guessed through whichever of them is open. A request with no code is not
        // counted: that is only starting a subscription, which has its own limit.
        RateLimiter::for('promotion-codes', fn (Request $request) => filled($request->input('promotion_code')) ? [
            Limit::perMinute(10)->by('promotion-codes:'.$byUserOrIp($request)),
            Limit::perHour(60)->by('promotion-codes-hourly:'.$byUserOrIp($request)),
        ] : Limit::none());

        // The page a customer lands on after paying re-checks the payment while it is pending. It has its
        // own allowance, per order, so waiting on a slow payment never competes with starting a checkout.
        RateLimiter::for('checkout-status', function (Request $request) use ($byUserOrIp) {
            $order = $request->route('order');
            $order = $order instanceof Order ? $order->public_id : (string) $order;

            return Limit::perMinute(30)->by('checkout-status:'.$byUserOrIp($request).'|'.$order);
        });
    }
}

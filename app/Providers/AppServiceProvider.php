<?php

namespace App\Providers;

use App\Models\Address;
use App\Models\Blog;
use App\Models\BlogComment;
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
        $this->app->singleton(SubscriptionManager::class, fn () => new SubscriptionManager);
        $this->app->singleton(HtmlSanitizer::class);
        $this->app->singleton(PaymentManager::class);
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

        // The page a customer lands on after paying re-checks the payment while it is pending. It has its
        // own allowance, per order, so waiting on a slow payment never competes with starting a checkout.
        RateLimiter::for('checkout-status', function (Request $request) use ($byUserOrIp) {
            $order = $request->route('order');
            $order = $order instanceof Order ? $order->public_id : (string) $order;

            return Limit::perMinute(30)->by('checkout-status:'.$byUserOrIp($request).'|'.$order);
        });
    }
}

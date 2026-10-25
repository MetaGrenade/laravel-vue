<?php

namespace App\Support\Onboarding;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\EmailVerification;
use App\Support\WebsiteSections;

/**
 * The "Get started" checklist on a new customer's dashboard (docs/onboarding.md).
 *
 * Nothing about the steps is stored. Each is done or not according to the account itself, so it ticks as the person does the thing,
 * wherever they do it, and a step that was done before the checklist existed is done. The one thing kept is that they hid it.
 */
class Onboarding
{
    /**
     * The checklist for a person, or null when there is none to show: it is off, they hid it, the account is older than the checklist
     * is shown for, no step applies to this site, or everything is done.
     *
     * @return array{steps: list<array{key: string, done: bool, url: string}>, done: int, total: int}|null
     */
    public function for(User $user): ?array
    {
        if (! $this->isShownTo($user)) {
            return null;
        }

        $steps = [];

        foreach ((array) config('onboarding.steps', []) as $key) {
            $step = is_string($key) ? $this->step($key, $user) : null;

            if ($step !== null) {
                $steps[$key] = $step;
            }
        }

        $done = count(array_filter($steps, fn (array $step) => $step['done']));

        if ($steps === [] || $done === count($steps)) {
            return null;
        }

        return ['steps' => array_values($steps), 'done' => $done, 'total' => count($steps)];
    }

    /**
     * Whether the checklist is for this person at all, whatever is done: switched on, not hidden by them, and the account new enough.
     */
    public function isShownTo(User $user): bool
    {
        if (! config('onboarding.enabled', true) || $user->onboarding_dismissed_at !== null) {
            return false;
        }

        $days = (int) config('onboarding.days', 30);

        return $days <= 0 || $user->created_at === null || $user->created_at->greaterThan(now()->subDays($days));
    }

    /**
     * Hide the checklist for good.
     */
    public function dismiss(User $user): void
    {
        $user->forceFill(['onboarding_dismissed_at' => now()])->save();
    }

    /**
     * One step: whether it is done and where to go to do it, or null when it is not one this site has (so that a step that could only
     * ever be done, or never be done, is not listed).
     *
     * @return array{key: string, done: bool, url: string}|null
     */
    private function step(string $key, User $user): ?array
    {
        $step = match ($key) {
            // Where it is required to sign in, a person who is on the dashboard has done it.
            'verify_email' => EmailVerification::isRequired() ? null : [
                'route' => 'verification.notice',
                'done' => fn () => $user->hasVerifiedEmail(),
            ],
            'profile' => [
                'route' => 'profile.edit',
                'done' => fn () => filled($user->avatar_url) || filled($user->profile_bio),
            ],
            'two_factor' => [
                'route' => 'security.edit',
                'done' => fn () => $user->two_factor_confirmed_at !== null,
            ],
            'notifications' => [
                'route' => 'settings.notifications.edit',
                'done' => fn () => $user->notificationSettings()->exists(),
            ],
            'plan' => SubscriptionPlan::query()->active()->exists() ? [
                'route' => 'pricing',
                'done' => fn () => $user->subscriptions()->exists(),
            ] : null,
            'api_key' => [
                'route' => 'settings.api-keys.index',
                'done' => fn () => $user->tokens()->whereNull('revoked_at')->exists(),
            ],
            'community' => WebsiteSections::isEnabled(WebsiteSections::FORUM) ? [
                'route' => 'forum.index',
                'done' => fn () => $user->forumThreads()->exists() || $user->forumPosts()->exists(),
            ] : null,
            default => null,
        };

        if ($step === null) {
            return null;
        }

        return ['key' => $key, 'done' => (bool) ($step['done'])(), 'url' => route($step['route'])];
    }
}

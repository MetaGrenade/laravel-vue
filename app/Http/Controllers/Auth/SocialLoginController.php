<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\OAuth\Exceptions\OAuthException;
use App\Support\OAuth\OAuthManager;
use App\Support\OAuth\OAuthProviders;
use App\Support\OAuth\OAuthUser;
use App\Support\OAuth\ProviderRegistry;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SocialLoginController extends Controller
{
    public function __construct(protected OAuthManager $manager)
    {
    }

    /**
     * Redirect the user to the OAuth provider.
     */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        $provider = strtolower($provider);
        $this->ensureProvider($provider);

        if ($request->user()) {
            $request->session()->put('oauth:link_user_id', $request->user()->getKey());
            $request->session()->put('oauth:link_redirect', route('security.edit'));
        } else {
            $request->session()->forget('oauth:link_user_id');
            $request->session()->forget('oauth:link_redirect');
        }

        return $this->manager->driver($provider)->redirect();
    }

    /**
     * Handle the provider callback.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $provider = strtolower($provider);
        $this->ensureProvider($provider);

        try {
            $oauthUser = $this->manager->driver($provider)->user($request);
        } catch (OAuthException $exception) {
            return $this->handleFailure($request, $exception->getMessage());
        }

        $linkingUserId = $request->session()->pull('oauth:link_user_id');
        $linkRedirect = $request->session()->pull('oauth:link_redirect');
        $currentUser = $request->user();

        if ($linkingUserId && $currentUser && (int) $linkingUserId === $currentUser->getKey()) {
            return $this->linkAccount($currentUser, $provider, $oauthUser, $linkRedirect ?: route('security.edit'));
        }

        return $this->loginWithProvider($request, $provider, $oauthUser);
    }

    protected function linkAccount(User $user, string $provider, OAuthUser $oauthUser, string $redirect): RedirectResponse
    {
        $conflictingAccount = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', $oauthUser->id)
            ->where('user_id', '!=', $user->getKey())
            ->first();

        if ($conflictingAccount) {
            return redirect($redirect)->with('status', 'social-account-conflict');
        }

        $account = $user->socialAccounts()->firstOrNew(['provider' => $provider]);
        $account->fill($this->socialAccountAttributes($oauthUser));
        $account->provider_id = $oauthUser->id;
        $account->save();

        return redirect($redirect)->with('status', 'social-account-linked');
    }

    protected function loginWithProvider(Request $request, string $provider, OAuthUser $oauthUser): RedirectResponse
    {
        $account = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', $oauthUser->id)
            ->first();

        $user = $account?->user;

        if (! $user && $oauthUser->email) {
            $user = User::query()->where('email', $oauthUser->email)->first();
        }

        if (! $user) {
            $user = $this->createUserFromProvider($provider, $oauthUser);
        }

        if ($user->is_banned ?? false) {
            return redirect()->route('login')->withErrors([
                'email' => trans('auth.banned'),
            ]);
        }

        if (! $account) {
            $account = new SocialAccount(['provider' => $provider]);
            $account->provider_id = $oauthUser->id;
        }

        $account->user()->associate($user);
        $account->fill($this->socialAccountAttributes($oauthUser));
        $account->save();

        Auth::login($user);

        if ($user->two_factor_confirmed_at) {
            $request->session()->put('two_factor:id', $user->getKey());
            $request->session()->put('two_factor:remember', false);

            Auth::logout();

            return redirect()->route('two-factor.login');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    protected function createUserFromProvider(string $provider, OAuthUser $oauthUser): User
    {
        $email = $oauthUser->email ?: $this->placeholderEmail($provider, $oauthUser->id);

        $user = User::create([
            'nickname' => $this->generateNickname($oauthUser),
            'email' => $email,
            'email_verified_at' => $oauthUser->email ? now() : null,
            'password' => Hash::make(Str::random(40)),
            'avatar_url' => $oauthUser->avatar,
        ]);

        event(new Registered($user));

        return $user;
    }

    protected function generateNickname(OAuthUser $oauthUser): string
    {
        $base = $oauthUser->nickname ?: $oauthUser->name ?: 'player';
        $base = Str::of($base)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9_]+/', '')
            ->trim('_');

        if ($base->isEmpty()) {
            $base = Str::of('player');
        }

        $base = (string) $base->limit(24, '');

        $nickname = $base;
        $counter = 1;

        while (User::query()->where('nickname', $nickname)->exists()) {
            $suffix = (string) $counter++;
            $nickname = Str::limit($base.$suffix, 32, '');

            if ($counter > 50) {
                $nickname = 'player_'.Str::lower(Str::random(8));
                break;
            }
        }

        return $nickname;
    }

    protected function placeholderEmail(string $provider, string $identifier): string
    {
        $base = sprintf('%s_%s@oauth.local', $provider, Str::lower($identifier));

        if (! User::query()->where('email', $base)->exists()) {
            return $base;
        }

        do {
            $email = sprintf('%s_%s@oauth.local', $provider, Str::lower(Str::random(12)));
        } while (User::query()->where('email', $email)->exists());

        return $email;
    }

    /**
     * Build the social account data for storage.
     *
     * @return array<string, mixed>
     */
    protected function socialAccountAttributes(OAuthUser $oauthUser): array
    {
        $expiresAt = $oauthUser->expiresIn ? Carbon::now()->addSeconds($oauthUser->expiresIn) : null;

        return [
            'name' => $oauthUser->name,
            'nickname' => $oauthUser->nickname,
            'email' => $oauthUser->email,
            'avatar' => $oauthUser->avatar,
            'access_token' => $oauthUser->accessToken,
            'refresh_token' => $oauthUser->refreshToken,
            'token_expires_at' => $expiresAt,
            'data' => $oauthUser->raw,
        ];
    }

    protected function handleFailure(Request $request, string $message): RedirectResponse
    {
        $linkRedirect = $request->session()->pull('oauth:link_redirect');
        $linkUserId = $request->session()->pull('oauth:link_user_id');

        if ($linkRedirect && $linkUserId) {
            return redirect($linkRedirect)->with('status', 'social-account-error');
        }

        return redirect()->route('login')->withErrors([
            'email' => $message,
        ]);
    }

    protected function ensureProvider(string $provider): void
    {
        if (! ProviderRegistry::supports($provider) || ! OAuthProviders::isEnabled($provider)) {
            abort(Response::HTTP_NOT_FOUND);
        }
    }
}

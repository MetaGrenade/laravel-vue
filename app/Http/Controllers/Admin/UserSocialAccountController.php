<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AttachSocialAccountRequest;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Security\SignInMethods;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class UserSocialAccountController extends Controller
{
    public function store(AttachSocialAccountRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('users.acp.update');

        $data = $request->validated();

        $account = $user->socialAccounts()->firstOrNew([
            'provider' => $data['provider'],
        ]);

        $conflict = SocialAccount::query()
            ->where('provider', $data['provider'])
            ->where('provider_id', $data['provider_id'])
            ->when($account->exists, fn ($query) => $query->whereKeyNot($account->getKey()))
            ->first();

        if ($conflict && $conflict->user_id !== $user->getKey()) {
            return back()->withErrors([
                'provider_id' => 'That account is already linked to a different user.',
            ]);
        }

        $account->provider_id = $data['provider_id'];
        $account->user()->associate($user);
        $account->fill([
            'name' => $data['name'] ?? null,
            'nickname' => $data['nickname'] ?? null,
            'email' => $data['email'] ?? null,
            'avatar' => $data['avatar'] ?? null,
        ]);
        $account->save();

        return redirect()->route('acp.users.edit', $user)->with('success', 'Social account linked.');
    }

    public function destroy(Request $request, User $user, SocialAccount $socialAccount): RedirectResponse
    {
        Gate::authorize('users.acp.update');

        if ($socialAccount->user_id !== $user->getKey()) {
            abort(Response::HTTP_NOT_FOUND);
        }

        // Taking away the only way a member has to sign in is allowed, since staff do it to cut off an account that is not the member's
        // (a provider linked by mistake, or by somebody else), but never by accident: it is refused with the reason until the request
        // says `force`, which the page sends from a second button.
        if (! $request->boolean('force') && ! SignInMethods::remainAfterUnlinking($user, $socialAccount)) {
            return back()->withErrors([
                'social_account' => 'This is the only way this member has to sign in: they have no other provider that is switched on, and no verified email address a reset link could reach. Disconnecting it locks them out until staff give them a way back in.',
            ]);
        }

        $socialAccount->delete();

        return redirect()->route('acp.users.edit', $user)->with('success', 'Social account detached.');
    }
}

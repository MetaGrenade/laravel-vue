<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AttachSocialAccountRequest;
use App\Models\SocialAccount;
use App\Models\User;
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

        $socialAccount->delete();

        return redirect()->route('acp.users.edit', $user)->with('success', 'Social account detached.');
    }
}

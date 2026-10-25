<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Models\User;
use App\Support\Forum\FollowedMembers;
use App\Support\Localization\DateFormatter;
use App\Support\Seo\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Following a member, and the feed of their public forum posts (docs/member-follows.md).
 */
class MemberFollowController extends Controller
{
    use InteractsWithInertiaPagination;

    public function index(Request $request, FollowedMembers $follows, Seo $seo): Response
    {
        $viewer = $request->user();

        abort_if($viewer === null, 403);

        $seo->title(__('members.following.title'))
            ->description(__('members.following.meta'))
            ->canonical(route('forum.following'))
            ->noindex();

        $page = $follows->feed($viewer);
        $formatter = DateFormatter::for($viewer);
        $posts = [];

        foreach ($page->getCollection() as $post) {
            $row = $follows->present($post, $formatter);

            if ($row !== null) {
                $posts[] = $row;
            }
        }

        return Inertia::render('ForumFollowing', [
            'people' => $follows->listing($viewer),
            'posts' => [
                'data' => $posts,
                ...$this->inertiaPagination($page),
            ],
        ]);
    }

    public function store(Request $request, User $user, FollowedMembers $follows): RedirectResponse
    {
        $viewer = $request->user();

        abort_if($viewer === null, 403);
        abort_if($user->is_banned, 404);

        $follows->follow($viewer, $user);

        return redirect()->back()->with('success', 'You are following '.$user->nickname.'.');
    }

    public function destroy(Request $request, User $user, FollowedMembers $follows): RedirectResponse
    {
        $viewer = $request->user();

        abort_if($viewer === null, 403);

        $follows->forget($viewer, $user);

        return redirect()->back()->with('success', 'You stopped following '.$user->nickname.'.');
    }
}

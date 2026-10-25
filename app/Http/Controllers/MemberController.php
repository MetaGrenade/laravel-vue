<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Models\User;
use App\Support\Localization\DateFormatter;
use App\Support\Members\Members;
use App\Support\Seo\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public member directory and a member's profile (docs/members.md).
 */
class MemberController extends Controller
{
    use InteractsWithInertiaPagination;

    public function index(Request $request, Members $members, Seo $seo): Response
    {
        $search = $request->query('q');
        $search = is_string($search) ? trim($search) : '';
        $search = Str::limit($search, 100, '');

        $sort = $request->query('sort');
        $sort = is_string($sort) && in_array($sort, ['newest', 'name', 'reputation'], true) ? $sort : 'newest';

        $page = $members->directory($search, $sort);
        $formatter = DateFormatter::for($request->user());
        $cards = [];

        foreach ($page->getCollection() as $member) {
            $cards[] = $members->card($member, $formatter);
        }

        $seo->title(__('members.directory.title'))
            ->description(__('members.directory.meta'))
            ->canonical(route('members.index'))
            ->schema([
                '@type' => 'CollectionPage',
                'name' => __('members.directory.title'),
                'url' => route('members.index'),
            ]);

        if ($search !== '' || $page->currentPage() > 1) {
            $seo->noindex();
        }

        return Inertia::render('Members', [
            'members' => [
                'data' => $cards,
                ...$this->inertiaPagination($page),
            ],
            'search' => $search,
            'sort' => $sort,
        ]);
    }

    public function show(Request $request, User $user, Members $members, Seo $seo): Response
    {
        // A banned account's page is how a spam bio would stay on the site. It is not there.
        abort_if($user->is_banned, 404);

        $profile = $members->profile($user, DateFormatter::for($request->user()));

        $seo->title($profile['nickname'])
            ->description($profile['bio'] ?? __('members.profile.meta', ['name' => $profile['nickname']]))
            ->canonical(route('members.show', $user))
            ->schema([
                '@type' => 'ProfilePage',
                'url' => route('members.show', $user),
                'mainEntity' => array_filter([
                    '@type' => 'Person',
                    'name' => $profile['nickname'],
                    'url' => route('members.show', $user),
                    'description' => $profile['bio'],
                    'image' => $profile['avatar_url'],
                ]),
            ]);

        if ($profile['avatar_url'] !== null) {
            $seo->image($profile['avatar_url'], $profile['nickname']);
        }

        return Inertia::render('Member', [
            'member' => $profile,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Models\User;
use App\Support\Localization\DateFormatter;
use App\Support\Members\DirectMessages;
use App\Support\Seo\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Private messages between two members (docs/direct-messages.md).
 */
class DirectMessageController extends Controller
{
    use InteractsWithInertiaPagination;

    public function index(Request $request, DirectMessages $messages, Seo $seo): Response
    {
        $viewer = $request->user();

        abort_if($viewer === null, 403);

        $seo->title(__('members.messages.title'))
            ->description(__('members.messages.meta'))
            ->canonical(route('messages.index'))
            ->noindex();

        $page = $messages->inbox($viewer);

        return Inertia::render('Messages', [
            'conversations' => [
                'data' => $messages->presentInbox($page, $viewer, DateFormatter::for($viewer)),
                ...$this->inertiaPagination($page),
            ],
        ]);
    }

    public function show(Request $request, User $user, DirectMessages $messages, Seo $seo): Response|RedirectResponse
    {
        $viewer = $request->user();

        abort_if($viewer === null, 403);

        if ($viewer->id === $user->id) {
            return redirect()->route('messages.index');
        }

        $conversation = $messages->between($viewer, $user);

        abort_if($user->is_banned && $conversation === null, 404);

        $page = $messages->transcript($viewer, $user);

        // The newest message is on the last page. Opening the conversation lands there.
        if (! $request->has('page') && $page->lastPage() > 1) {
            if ($conversation !== null) {
                $messages->markRead($viewer, $conversation);
            }

            return redirect()->route('messages.show', ['user' => $user, 'page' => $page->lastPage()]);
        }

        if ($conversation !== null) {
            $messages->markRead($viewer, $conversation);
        }

        $seo->title(__('members.messages.with', ['name' => $user->nickname]))
            ->description(__('members.messages.meta'))
            ->canonical(route('messages.show', $user))
            ->noindex();

        $block = $messages->block($viewer, $user);

        return Inertia::render('DirectConversation', [
            'member' => [
                'id' => $user->id,
                'nickname' => (string) $user->nickname,
                'banned' => (bool) $user->is_banned,
            ],
            'messages' => [
                'data' => $messages->presentTranscript($page, $viewer, DateFormatter::for($viewer)),
                ...$this->inertiaPagination($page),
            ],
            'blocked' => $block === 'self' ? null : $block,
        ]);
    }

    public function store(Request $request, User $user, DirectMessages $messages): RedirectResponse
    {
        $viewer = $request->user();

        abort_if($viewer === null, 403);

        $existing = $messages->between($viewer, $user);

        abort_if($user->is_banned && $existing === null, 404);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $messages->send($viewer, $user, $validated['body']);

        return redirect()
            ->route('messages.show', $user)
            ->with('success', 'Your message was sent.');
    }
}

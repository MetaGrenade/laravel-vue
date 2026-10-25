<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AutomodRuleRequest;
use App\Models\AutomodRule;
use App\Support\Localization\DateFormatter;
use App\Support\Moderation\Automod;
use App\Support\Moderation\AutomodVerdict;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The rules of the forum's automatic moderation (docs/automod.md), and a box to try a text against them. Everything here needs
 * `forums.acp.automod`.
 */
class AutomodRuleController extends Controller
{
    public function index(Request $request, Automod $automod): Response
    {
        abort_unless($request->user()?->can('forums.acp.automod'), 403);

        $formatter = DateFormatter::for($request->user());

        $request->validate(['test' => ['nullable', 'string', 'max:5000']]);
        $test = trim((string) $request->query('test', ''));

        return Inertia::render('acp/ForumAutomod', [
            'rules' => AutomodRule::query()->orderBy('id')->get()->map(fn (AutomodRule $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'type' => $rule->type,
                'settings' => $rule->settings,
                'action' => $rule->action,
                'only_new_days' => $rule->only_new_days,
                'is_enabled' => $rule->is_enabled,
                'hits' => $rule->hits,
                'last_hit_at' => $formatter->iso($rule->last_hit_at),
            ])->all(),
            // What the rules make of a text a moderator pasted in: null until they have, then who would catch it.
            'tested' => $test === '' ? null : array_map(fn (AutomodVerdict $verdict) => [
                'rule' => $verdict->rule->name,
                'action' => $verdict->rule->action,
                'detail' => $verdict->detail,
            ], $automod->test('', $test)),
            'test' => $test,
        ]);
    }

    public function store(AutomodRuleRequest $request): RedirectResponse
    {
        $rule = AutomodRule::query()->create($request->ruleAttributes());

        return back()->with('success', "The rule \"{$rule->name}\" has been added.");
    }

    public function update(AutomodRuleRequest $request, AutomodRule $rule): RedirectResponse
    {
        $rule->update($request->ruleAttributes());

        return back()->with('success', "The rule \"{$rule->name}\" has been saved.");
    }

    public function enabled(Request $request, AutomodRule $rule): RedirectResponse
    {
        abort_unless($request->user()?->can('forums.acp.automod'), 403);

        $validated = $request->validate(['is_enabled' => ['required', 'boolean']]);

        $rule->update(['is_enabled' => (bool) $validated['is_enabled']]);

        return back()->with('success', $rule->is_enabled ? "The rule \"{$rule->name}\" is on." : "The rule \"{$rule->name}\" is off.");
    }

    public function destroy(Request $request, AutomodRule $rule): RedirectResponse
    {
        abort_unless($request->user()?->can('forums.acp.automod'), 403);

        $rule->delete();

        return back()->with('success', "The rule \"{$rule->name}\" has been deleted. What it held stays in the review queue.");
    }
}

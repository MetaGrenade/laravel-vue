<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSupportAssignmentRuleRequest;
use App\Http\Requests\Admin\UpdateSupportAssignmentRuleRequest;
use App\Models\SupportAssignmentRule;
use App\Models\SupportTicketCategory;
use App\Models\User;
use App\Support\Localization\DateFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SupportAssignmentRuleController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('support_assignment_rules.acp.view'), 403);

        $formatter = DateFormatter::for($request->user());

        $rules = SupportAssignmentRule::query()
            ->with(['category:id,name', 'assignee:id,nickname,email'])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (SupportAssignmentRule $rule) use ($formatter) {
                return [
                    'id' => $rule->id,
                    'support_ticket_category_id' => $rule->support_ticket_category_id,
                    'priority' => $rule->priority,
                    'assigned_to' => $rule->assigned_to,
                    'position' => $rule->position,
                    'active' => $rule->active,
                    'category' => $rule->category ? [
                        'id' => $rule->category->id,
                        'name' => $rule->category->name,
                    ] : null,
                    'assignee' => $rule->assignee ? [
                        'id' => $rule->assignee->id,
                        'nickname' => $rule->assignee->nickname,
                        'email' => $rule->assignee->email,
                    ] : null,
                    'created_at' => $formatter->iso($rule->created_at),
                    'updated_at' => $formatter->iso($rule->updated_at),
                ];
            })
            ->values()
            ->all();

        $categories = SupportTicketCategory::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (SupportTicketCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
            ])
            ->values()
            ->all();

        $agents = User::query()
            ->orderBy('nickname')
            ->get(['id', 'nickname', 'email'])
            ->map(fn (User $agent) => [
                'id' => $agent->id,
                'nickname' => $agent->nickname,
                'email' => $agent->email,
            ])
            ->values()
            ->all();

        return Inertia::render('acp/SupportAssignmentRules', [
            'rules' => $rules,
            'categories' => $categories,
            'agents' => $agents,
            'can' => [
                'create' => (bool) $request->user()?->can('support_assignment_rules.acp.create'),
                'edit' => (bool) $request->user()?->can('support_assignment_rules.acp.edit'),
                'delete' => (bool) $request->user()?->can('support_assignment_rules.acp.delete'),
            ],
        ]);
    }

    public function store(StoreSupportAssignmentRuleRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $nextPosition = (int) SupportAssignmentRule::max('position');

        SupportAssignmentRule::create(array_merge($validated, [
            'position' => $nextPosition + 1,
        ]));

        return redirect()
            ->route('acp.support.assignment-rules.index')
            ->with('success', 'Assignment rule created.');
    }

    public function update(UpdateSupportAssignmentRuleRequest $request, SupportAssignmentRule $rule): RedirectResponse
    {
        $validated = $request->validated();

        $rule->update($validated);

        return redirect()
            ->route('acp.support.assignment-rules.index')
            ->with('success', 'Assignment rule updated.');
    }

    public function destroy(Request $request, SupportAssignmentRule $rule): RedirectResponse
    {
        abort_unless($request->user()?->can('support_assignment_rules.acp.delete'), 403);

        $position = $rule->position;

        $rule->delete();

        if ($position !== null) {
            SupportAssignmentRule::query()
                ->where('position', '>', $position)
                ->decrement('position');
        }

        return redirect()
            ->route('acp.support.assignment-rules.index')
            ->with('success', 'Assignment rule deleted.');
    }

    public function reorder(Request $request, SupportAssignmentRule $rule): RedirectResponse
    {
        abort_unless($request->user()?->can('support_assignment_rules.acp.edit'), 403);

        $validated = $request->validate([
            'direction' => ['required', Rule::in(['up', 'down'])],
        ]);

        $direction = $validated['direction'];

        $neighbor = SupportAssignmentRule::query()
            ->when(
                $direction === 'up',
                fn ($query) => $query
                    ->where('position', '<', $rule->position)
                    ->orderByDesc('position')
                    ->orderByDesc('id'),
                fn ($query) => $query
                    ->where('position', '>', $rule->position)
                    ->orderBy('position')
                    ->orderBy('id')
            )
            ->first();

        if (! $neighbor) {
            $message = $direction === 'up'
                ? 'Rule is already at the top.'
                : 'Rule is already at the bottom.';

            throw ValidationException::withMessages([
                'direction' => $message,
            ]);
        }

        $currentPosition = $rule->position;
        $rule->update(['position' => $neighbor->position]);
        $neighbor->update(['position' => $currentPosition]);

        return redirect()
            ->route('acp.support.assignment-rules.index')
            ->with('success', 'Assignment rule order updated.');
    }
}

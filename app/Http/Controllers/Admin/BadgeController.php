<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BadgeRequest;
use App\Models\Badge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Response;
use Inertia\Inertia;

class BadgeController extends Controller
{
    public function index(): Response
    {
        $badges = Badge::query()
            ->withCount('users')
            ->orderBy('points_required')
            ->orderBy('name')
            ->get()
            ->map(function (Badge $badge) {
                return [
                    'id' => $badge->id,
                    'name' => $badge->name,
                    'slug' => $badge->slug,
                    'description' => $badge->description,
                    'points_required' => $badge->points_required,
                    'is_active' => $badge->is_active,
                    'awarded_count' => $badge->users_count,
                    'created_at' => $badge->created_at?->toIso8601String(),
                    'updated_at' => $badge->updated_at?->toIso8601String(),
                ];
            })->values();

        return Inertia::render('acp/Badges', [
            'badges' => $badges,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('acp/BadgeCreate');
    }

    public function store(BadgeRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['slug'] = $this->resolveSlug($data['slug'] ?? null, $data['name']);

        Badge::create($data);

        return redirect()->route('acp.reputation.badges.index')
            ->with('success', 'Badge created successfully.');
    }

    public function edit(Badge $badge): Response
    {
        return Inertia::render('acp/BadgeEdit', [
            'badge' => [
                'id' => $badge->id,
                'name' => $badge->name,
                'slug' => $badge->slug,
                'description' => $badge->description,
                'points_required' => $badge->points_required,
                'is_active' => $badge->is_active,
            ],
        ]);
    }

    public function update(BadgeRequest $request, Badge $badge): RedirectResponse
    {
        $data = $request->validated();
        $data['slug'] = $this->resolveSlug($data['slug'] ?? null, $data['name'], $badge);

        $badge->update($data);

        return redirect()->route('acp.reputation.badges.index')
            ->with('success', 'Badge updated successfully.');
    }

    public function destroy(Badge $badge): RedirectResponse
    {
        $badge->delete();

        return redirect()->route('acp.reputation.badges.index')
            ->with('success', 'Badge deleted successfully.');
    }

    private function resolveSlug(?string $slug, string $name, ?Badge $ignore = null): string
    {
        $baseSlug = Str::slug($slug ?: $name);
        if ($baseSlug === '') {
            $baseSlug = Str::slug($name) ?: Str::random(8);
        }

        $candidate = Str::limit($baseSlug, 100, '');
        $suffix = 1;

        while (Badge::query()
            ->where('slug', $candidate)
            ->when($ignore, fn ($query, $model) => $query->where('id', '!=', $model->id))
            ->exists()) {
            $candidate = Str::limit($baseSlug, 100, '') . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }
}

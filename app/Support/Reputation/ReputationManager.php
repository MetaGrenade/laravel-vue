<?php

namespace App\Support\Reputation;

use App\Models\Badge;
use App\Models\ReputationEvent;
use App\Models\User;
use App\Support\Database\Transaction;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class ReputationManager
{
    public function __construct(private readonly DatabaseManager $db)
    {
    }

    public function record(string $eventKey, User $user, ?Model $source = null, array $metadata = []): ?ReputationEvent
    {
        $config = config('reputation.events', []);
        $eventConfig = Arr::get($config, $eventKey);

        if (!$eventConfig) {
            return null;
        }

        $points = (int) ($eventConfig['points'] ?? 0);

        if ($points === 0) {
            return null;
        }

        return Transaction::run(function () use ($eventKey, $user, $source, $metadata, $points) {
            $event = ReputationEvent::create([
                'user_id' => $user->id,
                'event' => $eventKey,
                'points' => $points,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'metadata' => empty($metadata) ? null : $metadata,
            ]);

            $user->increment('reputation_points', $points);

            $this->syncBadges($user->fresh(['badges']));

            return $event;
        }, $this->db);
    }

    public function syncBadges(User $user): void
    {
        $eligibleBadges = Badge::query()
            ->where('is_active', true)
            ->where('points_required', '<=', $user->reputation_points)
            ->orderBy('points_required')
            ->get(['id']);

        if ($eligibleBadges->isEmpty()) {
            return;
        }

        $awardedBadgeIds = $user->badges()->pluck('badges.id');
        $now = Carbon::now();

        $toAttach = $eligibleBadges
            ->reject(fn ($badge) => $awardedBadgeIds->contains($badge->id))
            ->mapWithKeys(fn ($badge) => [
                $badge->id => [
                    'awarded_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ])->all();

        if (!empty($toAttach)) {
            $user->badges()->attach($toAttach);
        }
    }
}

<?php

namespace App\Support\Notifications;

use App\Models\EmailDigestItem;
use App\Models\User;
use App\Notifications\DailyDigest;
use App\Support\Localization\Locales;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Holds community emails for members who asked for one a day, and sends that email (docs/email-digests.md).
 */
class EmailDigest
{
    public const PER_CATEGORY = 100;

    /**
     * Keep the email for the morning batch. Returns false when this notice has nothing the digest can show,
     * so the caller sends the email at once instead.
     */
    public function hold(User $user, string $category, RecordsForDigest $notification): bool
    {
        // The line is kept in the language of the person it is for, the one their email will be in: it is made now, while the request
        // that caused it may be in another.
        $payload = Locales::within($user->preferredLocale(), fn () => $notification->toArray($user));
        $title = $payload['title'] ?? null;
        $url = $payload['url'] ?? null;

        if (! is_string($title) || trim($title) === '' || ! is_string($url)) {
            return false;
        }

        $url = $this->acceptableUrl($url);

        if ($url === null) {
            return false;
        }

        $waiting = EmailDigestItem::query()
            ->where('user_id', $user->id)
            ->where('category', $category)
            ->count();

        if ($waiting >= self::PER_CATEGORY) {
            return true;
        }

        $excerpt = $payload['excerpt'] ?? null;
        $excerpt = is_string($excerpt) ? trim($excerpt) : '';

        EmailDigestItem::query()->create([
            'user_id' => $user->id,
            'category' => $category,
            'title' => Str::limit(trim($title), 180),
            'excerpt' => $excerpt === '' ? null : Str::limit($excerpt, 180),
            'url' => $url,
        ]);

        return true;
    }

    /**
     * The next members who have something waiting, lowest id first: at most `$limit` of them, all with an id above `$after`. The morning
     * job walks the list with this, a page at a time, so it never holds more than a page of ids and is not disturbed by the lines the
     * jobs it queues are deleting.
     *
     * @return list<int>
     */
    public function waitingMemberIds(int $after, int $limit): array
    {
        return EmailDigestItem::query()
            ->where('user_id', '>', $after)
            ->distinct()
            ->orderBy('user_id')
            ->limit($limit)
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Send one email to each of these members who has something waiting, then drop those lines. Returns how many were emailed.
     *
     * A member whose delivery fails (a bad row, a mail error that is raised while the email is queued) is reported and left with their
     * lines for the next morning; it does not stop the members after them.
     *
     * @param  list<int>  $userIds
     */
    public function deliverTo(array $userIds): int
    {
        $sent = 0;

        foreach ($userIds as $userId) {
            try {
                if ($this->deliver($userId)) {
                    $sent++;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $sent;
    }

    /**
     * Claim this member's waiting lines and queue their email. A second run finds nothing left to send.
     */
    private function deliver(int $userId): bool
    {
        try {
            return DB::transaction(function () use ($userId): bool {
                // The lock is taken before the email is queued. Two jobs then cannot both send the same lines.
                $items = EmailDigestItem::query()
                    ->where('user_id', $userId)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $user = User::query()->find($userId);

                if (! $user instanceof User || $user->is_banned) {
                    $this->deleteClaimed($items);

                    return false;
                }

                if (! $user->hasVerifiedEmail()) {
                    EmailDigestItem::query()
                        ->where('user_id', $user->id)
                        ->where('created_at', '<', now()->subDays(14))
                        ->delete();

                    return false;
                }

                $lines = [];
                $drop = [];

                foreach ($items as $item) {
                    $channels = $user->preferredNotificationChannelsFor($item->category, ['mail']);

                    if (! in_array('mail', $channels, true)) {
                        $drop[] = $item->id;

                        continue;
                    }

                    $lines[] = [
                        'category' => $item->category,
                        'title' => $item->title,
                        'excerpt' => $item->excerpt,
                        'url' => $item->url,
                    ];
                    $drop[] = $item->id;
                }

                if ($drop === []) {
                    return false;
                }

                $deleted = EmailDigestItem::query()->whereIn('id', $drop)->delete();

                if ($deleted !== count($drop)) {
                    throw new EmailDigestClaimMissed;
                }

                if ($lines !== []) {
                    $user->notify(new DailyDigest($lines));
                }

                return $lines !== [];
            });
        } catch (EmailDigestClaimMissed) {
            return false;
        }
    }

    /**
     * @param  Collection<int, EmailDigestItem>  $items
     */
    private function deleteClaimed(Collection $items): void
    {
        if ($items->isEmpty()) {
            return;
        }

        EmailDigestItem::query()->whereKey($items->modelKeys())->delete();
    }

    private function acceptableUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $app = parse_url((string) config('app.url'));

        if (! is_array($parts) || ! is_array($app)) {
            return null;
        }

        $scheme = $parts['scheme'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (($parts['host'] ?? null) !== ($app['host'] ?? null)) {
            return null;
        }

        $port = $parts['port'] ?? null;
        $appPort = $app['port'] ?? null;

        if ($port !== $appPort) {
            return null;
        }

        return $url;
    }
}

/**
 * Another morning job already took these lines, so this attempt must not queue an email.
 */
final class EmailDigestClaimMissed extends \RuntimeException {}

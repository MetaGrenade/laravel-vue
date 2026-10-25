<?php

namespace App\Support\Notifications;

use App\Models\EmailDigestItem;
use App\Models\User;
use App\Notifications\DailyDigest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        $payload = $notification->toArray($user);
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
     * Send one email to each member who has something waiting, then drop those lines.
     */
    public function sendDue(): int
    {
        $sent = 0;

        $userIds = EmailDigestItem::query()->distinct()->orderBy('user_id')->pluck('user_id');

        foreach ($userIds as $userId) {
            $user = User::query()->find($userId);

            if (! $user instanceof User) {
                EmailDigestItem::query()->where('user_id', $userId)->delete();

                continue;
            }

            if ($user->is_banned) {
                EmailDigestItem::query()->where('user_id', $user->id)->delete();

                continue;
            }

            if (! $user->hasVerifiedEmail()) {
                EmailDigestItem::query()
                    ->where('user_id', $user->id)
                    ->where('created_at', '<', now()->subDays(14))
                    ->delete();

                continue;
            }

            $items = EmailDigestItem::query()
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->get();

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

            if ($lines === []) {
                if ($drop !== []) {
                    EmailDigestItem::query()->whereIn('id', $drop)->delete();
                }

                continue;
            }

            DB::transaction(function () use ($user, $lines, $drop): void {
                $user->notify(new DailyDigest($lines));
                EmailDigestItem::query()->whereIn('id', $drop)->delete();
            });

            $sent++;
        }

        return $sent;
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

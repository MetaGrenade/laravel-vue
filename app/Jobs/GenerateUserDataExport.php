<?php

namespace App\Jobs;

use App\Models\DataExport;
use App\Models\User;
use App\Notifications\UserDataExportReady;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

class GenerateUserDataExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $exportId)
    {
    }

    public function handle(): void
    {
        $export = DataExport::with('user')->findOrFail($this->exportId);

        $export->update([
            'status' => DataExport::STATUS_PROCESSING,
            'failure_reason' => null,
        ]);

        $user = $export->user;

        if (! $user) {
            throw new RuntimeException('User not found for export.');
        }

        $user->loadMissing('notificationSettings');

        try {
            $payload = $this->buildPayload($user);
            $csvContent = $this->buildCsv($payload);

            $disk = Storage::disk('local');
            $disk->makeDirectory('exports');

            $relativePath = sprintf('exports/user-%d-%s.zip', $user->id, now()->format('Ymd_His'));
            $zipPath = $disk->path($relativePath);

            $zip = new ZipArchive();
            $created = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

            if ($created !== true) {
                throw new RuntimeException('Unable to create export archive.');
            }

            $zip->addFromString('export.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $zip->addFromString('export.csv', $csvContent);
            $zip->close();

            $export->update([
                'status' => DataExport::STATUS_COMPLETED,
                'file_path' => $relativePath,
                'completed_at' => now(),
            ]);

            $export->refresh();

            $notification = new UserDataExportReady($export);

            $channels = $user->preferredNotificationChannelsFor('privacy', ['database', 'mail']);

            $synchronousChannels = array_values(array_intersect($channels, ['database']));
            $queuedChannels = array_values(array_diff($channels, $synchronousChannels));

            if ($synchronousChannels !== []) {
                Notification::sendNow($user, $notification->withChannels($synchronousChannels));
            }

            if ($queuedChannels !== []) {
                Notification::send($user, $notification->withChannels($queuedChannels));
            }
        } catch (Throwable $exception) {
            $export->update([
                'status' => DataExport::STATUS_FAILED,
                'failure_reason' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayload(User $user): array
    {
        $blogComments = $user->blogComments()
            ->select('id', 'blog_id', 'body', 'created_at', 'updated_at')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($comment) => [
                'id' => $comment->id,
                'blog_id' => $comment->blog_id,
                'body' => $comment->body,
                'created_at' => $comment->created_at?->toIso8601String(),
                'updated_at' => $comment->updated_at?->toIso8601String(),
            ])
            ->all();

        $forumPosts = $user->forumPosts()
            ->select('id', 'forum_thread_id', 'body', 'created_at', 'updated_at')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($post) => [
                'id' => $post->id,
                'forum_thread_id' => $post->forum_thread_id,
                'body' => $post->body,
                'created_at' => $post->created_at?->toIso8601String(),
                'updated_at' => $post->updated_at?->toIso8601String(),
            ])
            ->all();

        $supportTickets = $user->supportTickets()
            ->select('id', 'subject', 'status', 'priority', 'created_at', 'updated_at')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($ticket) => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'created_at' => $ticket->created_at?->toIso8601String(),
                'updated_at' => $ticket->updated_at?->toIso8601String(),
            ])
            ->all();

        return [
            'user' => [
                'id' => $user->id,
                'nickname' => $user->nickname,
                'email' => $user->email,
                'timezone' => $user->timezone,
                'locale' => $user->locale,
                'created_at' => $user->created_at?->toIso8601String(),
                'updated_at' => $user->updated_at?->toIso8601String(),
            ],
            'profile' => [
                'avatar_url' => $user->avatar_url,
                'profile_bio' => $user->profile_bio,
                'forum_signature' => $user->forum_signature,
                'social_links' => $user->social_links,
            ],
            'blog_comments' => $blogComments,
            'forum_posts' => $forumPosts,
            'support_tickets' => $supportTickets,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function buildCsv(array $payload): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open temporary stream for export.');
        }

        fputcsv($handle, ['resource_type', 'resource_id', 'summary', 'created_at', 'updated_at']);

        foreach ($payload['blog_comments'] as $comment) {
            fputcsv($handle, [
                'blog_comment',
                $comment['id'],
                Str::limit((string) $comment['body'], 120),
                $comment['created_at'],
                $comment['updated_at'],
            ]);
        }

        foreach ($payload['forum_posts'] as $post) {
            fputcsv($handle, [
                'forum_post',
                $post['id'],
                Str::limit((string) $post['body'], 120),
                $post['created_at'],
                $post['updated_at'],
            ]);
        }

        foreach ($payload['support_tickets'] as $ticket) {
            fputcsv($handle, [
                'support_ticket',
                $ticket['id'],
                Str::limit((string) $ticket['subject'], 120),
                $ticket['created_at'],
                $ticket['updated_at'],
            ]);
        }

        rewind($handle);

        $contents = stream_get_contents($handle);
        fclose($handle);

        if ($contents === false) {
            throw new RuntimeException('Unable to read export CSV contents.');
        }

        return $contents;
    }
}

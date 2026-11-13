<?php

namespace App\Jobs;

use App\Models\DataExport;
use App\Models\ModerationAction;
use App\Models\UsageEvent;
use App\Models\User;
use App\Notifications\UserDataExportReady;
use App\Support\Csv\CsvCell;
use App\Support\Csv\CsvRow;
use App\Support\Json\StreamedObject;
use App\Support\Notifications\SplitDelivery;
use Closure;
use Generator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Everything the site holds about a member, as a zip they download: `export.json` (all of it, as typed) and `export.csv` (one row a thing, with a
 * short summary, to read in a spreadsheet).
 *
 * Nothing is held whole. A member who has posted for years has tens of thousands of posts, and the export used to read them all into models, copy them
 * into arrays, encode the lot as one string and hand a copy of that to the zip; at 50,000 posts that did not fit in the 256 MB a worker is given, and
 * PHP's out-of-memory error is not an exception, so nothing here could catch it. Now each list is read a page of rows at a time and written to a file
 * as it goes, and the zip takes the two files as they are.
 */
class GenerateUserDataExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * What the member is told when an export fails. They see it on their privacy page, so it is a sentence and not the exception (a query's
     * message has its SQL and values in it); the exception goes to the log and the failed jobs table.
     */
    public const FAILURE_REASON = 'The export could not be made. Please request it again, and contact support if it fails again.';

    /**
     * Rows read at a time. A post can be long, so this is a page of bodies that is held, and not much more.
     */
    protected const PAGE = 200;

    public function __construct(public int $exportId) {}

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

        try {
            $disk = Storage::disk('local');
            $disk->makeDirectory('exports');

            $relativePath = sprintf('exports/user-%d-%s.zip', $user->id, now()->format('Ymd_His'));

            $this->archive($user, $disk->path($relativePath));

            $export->update([
                'status' => DataExport::STATUS_COMPLETED,
                'file_path' => $relativePath,
                'completed_at' => now(),
            ]);

            $export->refresh();

            $notification = new UserDataExportReady($export);

            $channels = $user->preferredNotificationChannelsFor('privacy', ['database', 'mail', 'push']);

            if (in_array('mail', $channels, true) && ! in_array('broadcast', $channels, true)) {
                $channels[] = 'broadcast';
            }

            app(SplitDelivery::class)->send($user, $channels, fn (array $part): UserDataExportReady => $notification->withChannels($part));
        } catch (Throwable $exception) {
            $this->markFailed();

            throw $exception;
        }
    }

    /**
     * The worker gave up on the job: it timed out, or it was killed (out of memory, a deploy) and the job came round again with no tries left. None
     * of those is an exception in `handle()`, so the catch there never ran, and the export stayed `processing`, which is the one state in which the
     * member cannot ask for another. A finished export is left as it is: a worker that was thought dead may have got to the end after all.
     */
    public function failed(?Throwable $exception = null): void
    {
        DataExport::query()
            ->whereKey($this->exportId)
            ->whereIn('status', [DataExport::STATUS_PENDING, DataExport::STATUS_PROCESSING])
            ->update([
                'status' => DataExport::STATUS_FAILED,
                'failure_reason' => self::FAILURE_REASON,
            ]);
    }

    private function markFailed(): void
    {
        DataExport::query()->whereKey($this->exportId)->update([
            'status' => DataExport::STATUS_FAILED,
            'failure_reason' => self::FAILURE_REASON,
        ]);
    }

    /**
     * Write both files and zip them. The files are `tmpfile()`s, which PHP removes when they are closed, and when the process ends (a fatal error
     * too), so a member's data is not left in the temp directory.
     */
    private function archive(User $user, string $zipPath): void
    {
        $json = $this->scratch();
        $csv = $this->scratch();

        try {
            $this->write($user, $json['handle'], $csv['handle']);

            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create export archive.');
            }

            $zip->addFile($json['path'], 'export.json');
            $zip->addFile($csv['path'], 'export.csv');

            // The zip reads the two files when it is closed, so they must still be there.
            if (! $zip->close()) {
                throw new RuntimeException('Unable to write export archive.');
            }
        } finally {
            fclose($json['handle']);
            fclose($csv['handle']);
        }
    }

    /**
     * @return array{handle: resource, path: string}
     */
    private function scratch(): array
    {
        $handle = tmpfile();

        if ($handle === false) {
            throw new RuntimeException('Unable to open a temporary file for export.');
        }

        return ['handle' => $handle, 'path' => stream_get_meta_data($handle)['uri']];
    }

    /**
     * `export.json` and `export.csv` in one pass. Each list below yields the item of the JSON and, as it does, writes the row of the CSV, so what a
     * thing is in the one and what it is in the other are written side by side. The CSV's rows come in the order of the JSON's lists.
     *
     * @param  resource  $json
     * @param  resource  $csv
     */
    private function write(User $user, $json, $csv): void
    {
        CsvRow::write($csv, ['resource_type', 'resource_id', 'summary', 'created_at', 'updated_at']);

        $document = new StreamedObject($json);

        $document->member('user', [
            'id' => $user->id,
            'nickname' => $user->nickname,
            'email' => $user->email,
            'timezone' => $user->timezone,
            'locale' => $user->locale,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ]);
        $document->member('profile', [
            'avatar_url' => $user->avatar_url,
            'profile_bio' => $user->profile_bio,
            'forum_signature' => $user->forum_signature,
            'social_links' => $user->social_links,
        ]);
        $document->list('blog_comments', $this->blogComments($user, $csv));
        $document->list('forum_posts', $this->forumPosts($user, $csv));
        $document->list('support_tickets', $this->supportTickets($user, $csv));
        $document->list('wishlist', $this->wishlist($user, $csv));
        $document->list('product_reviews', $this->productReviews($user, $csv));
        $document->list('api_keys', $this->apiKeys($user, $csv));
        $document->list('usage', $this->usage($user, $csv));
        $document->list('moderation', $this->moderation($user, $csv));
        $document->finish();
    }

    /**
     * @param  resource  $csv
     * @return Generator<int, array<string, mixed>>
     */
    private function blogComments(User $user, $csv): Generator
    {
        $comments = $this->inOrder(
            fn () => $user->blogComments(),
            ['created_at'],
            fn ($query) => $query->select('id', 'blog_id', 'body', 'created_at', 'updated_at'),
        );

        foreach ($comments as $comment) {
            $item = [
                'id' => $comment->id,
                'blog_id' => $comment->blog_id,
                'body' => $comment->body,
                'created_at' => $comment->created_at?->toIso8601String(),
                'updated_at' => $comment->updated_at?->toIso8601String(),
            ];

            $this->csvRow($csv, 'blog_comment', $item['id'], Str::limit((string) $item['body'], 120), $item['created_at'], $item['updated_at']);

            yield $item;
        }
    }

    /**
     * @param  resource  $csv
     * @return Generator<int, array<string, mixed>>
     */
    private function forumPosts(User $user, $csv): Generator
    {
        $posts = $this->inOrder(
            fn () => $user->forumPosts(),
            ['created_at'],
            fn ($query) => $query->select('id', 'forum_thread_id', 'body', 'created_at', 'updated_at'),
        );

        foreach ($posts as $post) {
            $item = [
                'id' => $post->id,
                'forum_thread_id' => $post->forum_thread_id,
                'body' => $post->body,
                'created_at' => $post->created_at?->toIso8601String(),
                'updated_at' => $post->updated_at?->toIso8601String(),
            ];

            $this->csvRow($csv, 'forum_post', $item['id'], Str::limit((string) $item['body'], 120), $item['created_at'], $item['updated_at']);

            yield $item;
        }
    }

    /**
     * @param  resource  $csv
     * @return Generator<int, array<string, mixed>>
     */
    private function supportTickets(User $user, $csv): Generator
    {
        $tickets = $this->inOrder(
            fn () => $user->supportTickets(),
            ['created_at'],
            fn ($query) => $query->select('id', 'subject', 'status', 'priority', 'created_at', 'updated_at'),
        );

        foreach ($tickets as $ticket) {
            $item = [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'created_at' => $ticket->created_at?->toIso8601String(),
                'updated_at' => $ticket->updated_at?->toIso8601String(),
            ];

            $this->csvRow($csv, 'support_ticket', $item['id'], Str::limit((string) $item['subject'], 120), $item['created_at'], $item['updated_at']);

            yield $item;
        }
    }

    /**
     * @param  resource  $csv
     * @return Generator<int, array<string, mixed>>
     */
    private function wishlist(User $user, $csv): Generator
    {
        $items = $this->inOrder(
            fn () => $user->wishlistItems(),
            ['created_at'],
            fn ($query) => $query->with('product:id,name'),
        );

        foreach ($items as $wished) {
            $item = [
                'id' => $wished->id,
                'product_id' => $wished->product_id,
                'product' => $wished->product?->name,
                'created_at' => $wished->created_at?->toIso8601String(),
                'updated_at' => $wished->updated_at?->toIso8601String(),
            ];

            $this->csvRow($csv, 'wishlist_item', $item['id'], Str::limit((string) $item['product'], 120), $item['created_at'], $item['updated_at']);

            yield $item;
        }
    }

    /**
     * @param  resource  $csv
     * @return Generator<int, array<string, mixed>>
     */
    private function productReviews(User $user, $csv): Generator
    {
        $reviews = $this->inOrder(
            fn () => $user->productReviews(),
            ['created_at'],
            fn ($query) => $query->with('product:id,name'),
        );

        foreach ($reviews as $review) {
            $item = [
                'id' => $review->id,
                'product_id' => $review->product_id,
                'product' => $review->product?->name,
                'rating' => $review->rating,
                'title' => $review->title,
                'body' => $review->body,
                'status' => $review->status->value,
                'verified_purchase' => $review->verified_purchase,
                'created_at' => $review->created_at?->toIso8601String(),
                'updated_at' => $review->updated_at?->toIso8601String(),
            ];

            $this->csvRow(
                $csv,
                'product_review',
                $item['id'],
                Str::limit($item['rating'].'/5 '.($item['product'] ?? '').': '.($item['title'] ?: $item['body']), 120),
                $item['created_at'],
                $item['updated_at'],
            );

            yield $item;
        }
    }

    /**
     * The API keys the account has made, revoked and expired ones too. Never the secret: it is shown once, and only a hash is kept.
     *
     * @param  resource  $csv
     * @return Generator<int, array<string, mixed>>
     */
    private function apiKeys(User $user, $csv): Generator
    {
        $keys = $this->inOrder(fn () => $user->tokens(), ['created_at']);

        foreach ($keys as $key) {
            $item = [
                'id' => $key->id,
                'name' => $key->name,
                'scopes' => (array) $key->abilities,
                'created_at' => $key->created_at?->toIso8601String(),
                'last_used_at' => $key->last_used_at?->toIso8601String(),
                'expires_at' => $key->expires_at?->toIso8601String(),
                'revoked_at' => $key->revoked_at?->toIso8601String(),
            ];

            // The last thing that happened to a key stands for when it was changed: it was revoked, or else last used.
            $this->csvRow(
                $csv,
                'api_key',
                $item['id'],
                Str::limit($item['name'].': '.implode(' ', $item['scopes']), 120),
                $item['created_at'],
                $item['revoked_at'] ?? $item['last_used_at'] ?? $item['created_at'],
            );

            yield $item;
        }
    }

    /**
     * What the account used of each metered thing, a row a day and meter (not a row a use: an API user has thousands). The database adds them up,
     * so what is read is a row a day, and a year of one meter is 365 of them.
     *
     * @param  resource  $csv
     * @return Generator<int, array<string, mixed>>
     */
    private function usage(User $user, $csv): Generator
    {
        $days = UsageEvent::query()
            ->where('user_id', $user->id)
            ->selectRaw('meter, DATE(occurred_at) as day, SUM(quantity) as units')
            ->groupBy('meter')
            ->groupByRaw('DATE(occurred_at)')
            ->orderBy('day')
            ->orderBy('meter')
            ->get();

        foreach ($days as $row) {
            $item = [
                'meter' => $row->getAttribute('meter'),
                'date' => substr((string) $row->getAttribute('day'), 0, 10),
                'units' => (int) $row->getAttribute('units'),
            ];

            // A row a day and meter, as in the JSON: the day is when it was used, and there is nothing to say it changed.
            $this->csvRow($csv, 'usage', $item['meter'].':'.$item['date'], $item['units'].' '.$item['meter'].' used', $item['date'], $item['date']);

            yield $item;
        }
    }

    /**
     * What moderators have told the member: the reason and when, as they can read it in their account. The note for staff and who they were are not
     * the member's to have. A warning, a ban or the lifting of one, as the member was told.
     *
     * @param  resource  $csv
     * @return Generator<int, array<string, mixed>>
     */
    private function moderation(User $user, $csv): Generator
    {
        $actions = $this->inOrder(fn () => $user->moderationActions()->whereIn('type', ModerationAction::TOLD_TO_MEMBER), []);

        foreach ($actions as $action) {
            $item = [
                'id' => $action->id,
                'type' => $action->type,
                'reason' => $action->reason,
                'about' => $action->subject_label,
                'ends_at' => $action->expires_at?->toIso8601String(),
                'created_at' => $action->created_at->toIso8601String(),
            ];

            $this->csvRow($csv, 'moderation_'.$item['type'], $item['id'], Str::limit((string) $item['reason'], 120), $item['created_at'], $item['created_at']);

            yield $item;
        }
    }

    /**
     * The rows of a query in the order it names, a page at a time. The ids are read first, in that order, which is cheap (a number a row, whatever the
     * rows hold), and then the rows themselves a page of ids at a time. A page that began where the last one ended, by an offset or by a value, would
     * skip or repeat a row if one were deleted or added while the export was being made; a list of ids does not.
     *
     * The order is `$order` and then the id, so that two rows with the same time always come out the same way round.
     *
     * @template TModel of Model
     *
     * @param  Closure(): Relation<TModel, *, *>  $query  a fresh query each time, for the ids and for each page
     * @param  list<string>  $order
     * @param  (Closure(Relation<TModel, *, *>): mixed)|null  $page  adds to the query of a page what it is read with: the columns, the relations
     * @return Generator<int, TModel>
     */
    private function inOrder(Closure $query, array $order, ?Closure $page = null): Generator
    {
        $ordered = $query();

        foreach ($order as $column) {
            $ordered->orderBy($column);
        }

        // One row at a time, so that what is held is the list of ids and not a row object for each of them (`pluck` reads them all first: about 500 bytes a row).
        $ids = [];

        foreach ($ordered->orderBy('id')->toBase()->select('id')->cursor() as $row) {
            $ids[] = $row->id;
        }

        foreach (array_chunk($ids, static::PAGE) as $slice) {
            $read = $query();
            $read->whereIn('id', $slice);

            if ($page !== null) {
                $page($read);
            }

            $rows = $read->get()->keyBy('id');

            foreach ($slice as $id) {
                // A row removed since the ids were read is not there any more, and is not in the export.
                if ($rows->has($id)) {
                    yield $rows->get($id);
                }
            }
        }
    }

    /**
     * One row of `export.csv`. The summary is what somebody typed: the member's own comment, post, ticket subject or key name, and also the reason a
     * moderator gave and the name of a product, which somebody else wrote. A spreadsheet runs a cell that starts with `=`, `+`, `-` or `@` as a formula
     * (CWE-1236), so the summary goes through `CsvCell::text()`; the rest of the row is ids, dates and fixed words. `CsvRow` writes the line.
     *
     * @param  resource  $handle
     */
    private function csvRow($handle, string $type, int|string $id, string $summary, ?string $createdAt, ?string $updatedAt): void
    {
        CsvRow::write($handle, [$type, $id, CsvCell::text($summary), $createdAt, $updatedAt]);
    }
}

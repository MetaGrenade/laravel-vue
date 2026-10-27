<?php

namespace App\Support\Forum;

use App\Models\ForumAttachment;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Support\Commerce\Catalogue\ImageProcessor;
use App\Support\Commerce\Catalogue\ImageRejected;
use App\Support\Routing\ZiggyRouteGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Pictures and files kept with a forum post (docs/forum-attachments.md).
 *
 * A picture is decoded and encoded again, so the file that is served is not the file that was uploaded.
 * Anything else is kept only when its contents are a PDF, plain text or a zip, and is only ever downloaded.
 */
class ForumAttachments
{
    /** @var array<string, string> */
    private const FILES = [
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'application/zip' => 'zip',
        'application/x-zip-compressed' => 'zip',
    ];

    /** @var array<string, string> */
    private const SERVE = [
        'image/webp' => 'inline',
        'application/pdf' => 'attachment',
        'text/plain' => 'attachment',
        'application/zip' => 'attachment',
    ];

    public function __construct(private readonly ImageProcessor $images) {}

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $max = max(1, (int) config('forum.attachments.max_files', 5));
        $kilobytes = max(1, (int) config('forum.attachments.max_kilobytes', 4096));

        return [
            'attachments' => ['nullable', 'array', 'max:'.$max],
            'attachments.*' => ['file', 'max:'.$kilobytes],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function removalRules(): array
    {
        return [
            'remove_attachments' => ['nullable', 'array'],
            'remove_attachments.*' => ['integer'],
        ];
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<array{bytes: string, mime: string, extension: string, name: string, width: int|null, height: int|null}>
     *
     * @throws ValidationException
     */
    public function prepare(array $files): array
    {
        if ($files !== []) {
            $this->guardPrivateDisk();
        }

        $prepared = [];

        foreach ($files as $index => $file) {
            try {
                $prepared[] = $this->prepareOne($file, $index);
            } catch (ImageRejected $exception) {
                throw ValidationException::withMessages([
                    'attachments.'.$index => $exception->getMessage(),
                ]);
            }
        }

        return $prepared;
    }

    /**
     * The files that stay, plus the ones being added, have to fit. An edit checks this before it saves anything.
     *
     * @param  list<int>  $removeIds
     *
     * @throws ValidationException
     */
    public function ensureCapacity(ForumPost $post, int $incoming, array $removeIds = []): void
    {
        $this->guardCapacity($post, $incoming, $this->removals($post, $removeIds)->count());
    }

    /**
     * @param  list<array{bytes: string, mime: string, extension: string, name: string, width: int|null, height: int|null}>  $prepared
     * @param  list<int>  $removeIds
     *
     * @throws ValidationException
     */
    public function sync(ForumPost $post, User $user, array $prepared, array $removeIds = []): void
    {
        $removing = $this->removals($post, $removeIds);

        $this->guardCapacity($post, count($prepared), $removing->count());

        // Write the replacements first. A failed write then leaves the files already on the post where they were.
        $this->keep($post, $user, $prepared);

        foreach ($removing as $attachment) {
            $attachment->delete();
        }
    }

    /**
     * @param  list<int>  $postIds
     */
    public function forget(array $postIds): void
    {
        if ($postIds === []) {
            return;
        }

        ForumAttachment::query()
            ->whereIn('forum_post_id', $postIds)
            ->get()
            ->each(fn (ForumAttachment $attachment) => $attachment->delete());
    }

    public function visibleTo(?User $viewer, ForumAttachment $attachment): bool
    {
        $post = ForumPost::query()
            ->withoutGlobalScope(ForumPost::VISIBLE)
            ->find($attachment->forum_post_id);

        if (! $post instanceof ForumPost) {
            return false;
        }

        $thread = ForumThread::query()->find($post->forum_thread_id);

        if (! $thread instanceof ForumThread) {
            return false;
        }

        $staff = $viewer !== null && $viewer->hasAnyRole(ZiggyRouteGroup::STAFF_ROLES);

        if (! $thread->is_published && ! $staff) {
            return false;
        }

        return $post->held_at === null || $staff || $viewer?->id === $post->user_id;
    }

    public function response(ForumAttachment $attachment): StreamedResponse
    {
        $disk = Storage::disk((string) $attachment->disk);

        abort_unless($attachment->path && $disk->exists($attachment->path), 404);

        $mime = strtolower((string) $attachment->mime_type);
        $disposition = self::SERVE[$mime] ?? 'attachment';
        $type = isset(self::SERVE[$mime]) ? $mime : 'application/octet-stream';

        $headers = [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ];

        if ($disposition === 'inline') {
            return $disk->response($attachment->path, $attachment->name, $headers);
        }

        return $disk->download($attachment->path, $attachment->name, $headers);
    }

    /**
     * @return list<array{id: int, name: string, size: string, image: bool, url: string}>
     */
    public function present(ForumPost $post): array
    {
        $rows = [];

        foreach ($post->attachments as $attachment) {
            $rows[] = [
                'id' => $attachment->id,
                'name' => $attachment->name,
                'size' => $this->sizeLabel((int) $attachment->size),
                'image' => str_starts_with((string) $attachment->mime_type, 'image/'),
                'url' => route('forum.attachments.show', $attachment),
            ];
        }

        return $rows;
    }

    /**
     * What `$request->file('attachments')` returns: one file, a list of them, or nothing.
     *
     * @return list<UploadedFile>
     */
    public function files(mixed $files): array
    {
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (! is_array($files)) {
            return [];
        }

        $uploaded = [];

        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $uploaded[] = $file;
            }
        }

        return $uploaded;
    }

    /**
     * @return array{bytes: string, mime: string, extension: string, name: string, width: int|null, height: int|null}
     *
     * @throws ImageRejected
     * @throws ValidationException
     */
    private function prepareOne(UploadedFile $file, int $index): array
    {
        $bytes = $file->getContent();

        if ($bytes === '') {
            throw ValidationException::withMessages([
                'attachments.'.$index => 'That file is empty.',
            ]);
        }

        $mime = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));

        if (isset(self::FILES[$mime])) {
            return [
                'bytes' => $bytes,
                'mime' => $mime === 'application/x-zip-compressed' ? 'application/zip' : $mime,
                'extension' => self::FILES[$mime],
                'name' => $this->displayName($file, self::FILES[$mime]),
                'width' => null,
                'height' => null,
            ];
        }

        if (str_starts_with($mime, 'image/')) {
            $processed = $this->images->process($bytes);

            return [
                'bytes' => $processed->large,
                'mime' => 'image/webp',
                'extension' => 'webp',
                'name' => $this->displayName($file, 'webp'),
                'width' => $processed->width,
                'height' => $processed->height,
            ];
        }

        throw ValidationException::withMessages([
            'attachments.'.$index => 'Upload a JPEG, PNG, WebP or GIF picture, or a PDF, text file or zip.',
        ]);
    }

    /**
     * @param  list<int>  $removeIds
     * @return Collection<int, ForumAttachment>
     */
    private function removals(ForumPost $post, array $removeIds): Collection
    {
        $removeIds = array_values(array_unique(array_map(intval(...), $removeIds)));

        if ($removeIds === []) {
            return new Collection;
        }

        return $post->attachments()->whereIn('id', $removeIds)->get();
    }

    private function guardCapacity(ForumPost $post, int $incoming, int $removing): void
    {
        $max = max(1, (int) config('forum.attachments.max_files', 5));
        $kept = $post->attachments()->count() - $removing;

        if ($kept + $incoming > $max) {
            throw ValidationException::withMessages([
                'attachments' => 'A post can have '.$max.' files.',
            ]);
        }
    }

    /**
     * @param  list<array{bytes: string, mime: string, extension: string, name: string, width: int|null, height: int|null}>  $prepared
     *
     * @throws ValidationException
     */
    private function keep(ForumPost $post, User $user, array $prepared): void
    {
        if ($prepared === []) {
            return;
        }

        $this->guardPrivateDisk();

        $diskName = (string) config('forum.attachments.disk', 'local');
        $directory = trim((string) config('forum.attachments.directory', 'forum-attachments'), '/').'/'.$post->id;
        $created = [];

        try {
            foreach ($prepared as $item) {
                $path = $directory.'/'.Str::ulid().'.'.$item['extension'];

                if (! Storage::disk($diskName)->put($path, $item['bytes'])) {
                    throw new \RuntimeException('The file could not be saved.');
                }

                $created[] = $post->attachments()->create([
                    'user_id' => $user->id,
                    'disk' => $diskName,
                    'path' => $path,
                    'name' => $item['name'],
                    'mime_type' => $item['mime'],
                    'size' => strlen($item['bytes']),
                    'width' => $item['width'],
                    'height' => $item['height'],
                ]);
            }
        } catch (Throwable $exception) {
            foreach ($created as $attachment) {
                $attachment->delete();
            }

            throw ValidationException::withMessages([
                'attachments' => 'The file could not be saved.',
            ]);
        }
    }

    /**
     * A post's files are only for people who can see the post. A disk the web
     * serves would hand them to anyone who learns the path.
     *
     * @throws ValidationException
     */
    private function guardPrivateDisk(): void
    {
        $disk = (string) config('forum.attachments.disk', 'local');

        if (config('filesystems.disks.'.$disk.'.visibility') !== 'public') {
            return;
        }

        throw ValidationException::withMessages([
            'attachments' => 'The forum attachment disk "'.$disk.'" is public, so anyone could fetch these files. Set FORUM_ATTACHMENT_DISK to a private disk.',
        ]);
    }

    private function displayName(UploadedFile $file, string $extension): string
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name);
        $stem = trim((string) pathinfo($name, PATHINFO_FILENAME), " .\t");
        $stem = $stem !== '' ? mb_substr($stem, 0, 120) : 'file';

        return $stem.'.'.$extension;
    }

    private function sizeLabel(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / 1024 / 1024, 1).' MB';
    }
}

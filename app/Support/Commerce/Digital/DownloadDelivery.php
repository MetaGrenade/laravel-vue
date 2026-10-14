<?php

namespace App\Support\Commerce\Digital;

use App\Models\DownloadCount;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\ProductFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands files to the people who bought them.
 *
 * What the order page shows ({@see self::forOrder()}) carries a short-lived signed link for each file
 * that can still be downloaded. The download route verifies the signature, then {@see self::claim()}
 * checks the grant and spends one of the file's downloads in a single conditional update, so two
 * requests racing for the last download cannot both get it. Only then is the file read.
 */
class DownloadDelivery
{
    /**
     * The downloads an order offers, line by line, for the order page.
     *
     * @return list<array{
     *     item_id: int,
     *     description: string,
     *     status: string,
     *     expires_at: string|null,
     *     files: list<array{id: int, name: string, size: int, sha256: string, remaining: int|null, state: string, url: string|null}>
     * }>
     */
    public function forOrder(Order $order): array
    {
        $grants = DownloadGrant::query()
            ->where('order_id', $order->id)
            ->with(['item', 'counts'])
            ->orderBy('id')
            ->get();

        if ($grants->isEmpty()) {
            return [];
        }

        $files = ProductFile::query()
            ->whereIn('product_id', $grants->pluck('product_id')->filter()->unique()->all())
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        $limit = (int) config('commerce.downloads.limit', 10);
        $orderCanDownload = $order->isPaid();

        return $grants->map(function (DownloadGrant $grant) use ($files, $limit, $orderCanDownload) {
            $usable = $orderCanDownload && $grant->isUsable();
            $counts = $grant->counts->keyBy('product_file_id');

            return [
                'item_id' => $grant->order_item_id,
                'description' => (string) ($grant->item->description ?? 'Item'),
                'status' => $grant->status(),
                'expires_at' => $grant->expires_at?->toIso8601String(),
                'files' => ($files[$grant->product_id] ?? collect())
                    ->map(function (ProductFile $file) use ($grant, $counts, $limit, $usable) {
                        $used = (int) ($counts[$file->id]->downloads ?? 0);
                        $remaining = $limit > 0 ? max(0, $limit - $used) : null;

                        $state = match (true) {
                            ! $usable => 'unavailable',
                            ! $file->is_active => 'unavailable',
                            $remaining === 0 => 'used_up',
                            default => 'ready',
                        };

                        return [
                            'id' => $file->id,
                            'name' => $file->name,
                            'size' => $file->size,
                            'sha256' => $file->sha256,
                            'remaining' => $remaining,
                            'state' => $state,
                            'url' => $state === 'ready' ? $this->link($grant, $file) : null,
                        ];
                    })->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * A signed link that works for a short while. The page makes a fresh one each time it is opened.
     */
    public function link(DownloadGrant $grant, ProductFile $file): string
    {
        return URL::temporarySignedRoute(
            'shop.downloads.show',
            now()->addMinutes((int) config('commerce.downloads.link_minutes', 30)),
            ['grant' => $grant->public_id, 'file' => $file->id],
        );
    }

    /**
     * Check that this grant may take this file now, and spend one of its downloads.
     *
     * @throws DownloadRefused
     */
    public function claim(DownloadGrant $grant, ProductFile $file): void
    {
        if ($grant->product_id === null || $file->product_id !== $grant->product_id) {
            throw new DownloadRefused('That file is not part of this order.', 404);
        }

        $order = $grant->order;

        if (! $order->isPaid()) {
            throw new DownloadRefused('This order has not been paid yet.', 403);
        }

        match ($grant->status()) {
            DownloadGrant::REVOKED => throw new DownloadRefused('Downloads for this order are no longer available.', 403),
            DownloadGrant::EXPIRED => throw new DownloadRefused('The downloads for this order have expired.', 410),
            default => null,
        };

        // Checked before a download is spent on it: a file that has gone missing must not use one up.
        if (! $file->is_active || ! $this->isStored($file)) {
            throw new DownloadRefused('This file is not available right now. Please try again later.', 404);
        }

        $limit = (int) config('commerce.downloads.limit', 10);

        DownloadCount::query()->insertOrIgnore([
            'download_grant_id' => $grant->id,
            'product_file_id' => $file->id,
            'downloads' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // One conditional update: of two requests for the last download, exactly one changes a row.
        $taken = DownloadCount::query()
            ->where('download_grant_id', $grant->id)
            ->where('product_file_id', $file->id)
            ->when($limit > 0, fn ($query) => $query->where('downloads', '<', $limit))
            ->update([
                'downloads' => DB::raw('downloads + 1'),
                'last_downloaded_at' => now(),
                'updated_at' => now(),
            ]);

        if ($taken === 0) {
            throw new DownloadRefused("You have downloaded this file the most times it allows ({$limit}). Contact us if you need it again.", 403);
        }
    }

    private function isStored(ProductFile $file): bool
    {
        return Storage::disk($file->disk)->exists($file->path);
    }

    /**
     * The file itself, always as an attachment of no particular type, so a browser saves it and never
     * renders or runs it. A disk that is not local hands out a short-lived link of its own.
     */
    public function respond(ProductFile $file): StreamedResponse|RedirectResponse
    {
        $disk = Storage::disk($file->disk);
        $driver = config("filesystems.disks.{$file->disk}.driver");

        if ($driver !== 'local' && $disk->providesTemporaryUrls()) {
            return redirect()->away($disk->temporaryUrl($file->path, now()->addMinutes(2), [
                'ResponseContentType' => 'application/octet-stream',
                'ResponseContentDisposition' => 'attachment; filename="'.addcslashes($file->original_name, '"\\').'"',
            ]));
        }

        return $disk->download($file->path, $file->original_name, [
            'Content-Type' => 'application/octet-stream',
            // Never let a browser sniff a different type or render the file.
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

<?php

namespace App\Support\Moderation;

use Illuminate\Support\Str;

/**
 * What {@see MemberContent::takeDown()} did, in numbers (docs/moderation.md).
 */
final readonly class ContentTakenDown
{
    /**
     * @param  int  $threadsHidden  Threads they started that were showing, now unpublished.
     * @param  int  $threadsRejected  Threads they started that were waiting in the review queue, now gone.
     * @param  int  $replies  Their replies in other people's threads (a held one among them), now deleted.
     * @param  int  $comments  Blog comments, now rejected.
     * @param  int  $reviews  Product reviews, now rejected.
     * @param  int  $reports  Reports about all that, now closed.
     */
    public function __construct(
        public int $threadsHidden = 0,
        public int $threadsRejected = 0,
        public int $replies = 0,
        public int $comments = 0,
        public int $reviews = 0,
        public int $reports = 0,
    ) {}

    /**
     * @param  array<string, mixed>|null  $details  As {@see toArray()} left it.
     */
    public static function fromDetails(?array $details): self
    {
        return new self(
            (int) ($details['threads_hidden'] ?? 0),
            (int) ($details['threads_rejected'] ?? 0),
            (int) ($details['replies'] ?? 0),
            (int) ($details['comments'] ?? 0),
            (int) ($details['reviews'] ?? 0),
            (int) ($details['reports'] ?? 0),
        );
    }

    /**
     * How many things of theirs came down. The reports closed are not among them: they are only the paperwork.
     */
    public function total(): int
    {
        return $this->threadsHidden + $this->threadsRejected + $this->replies + $this->comments + $this->reviews;
    }

    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }

    public function withReports(int $reports): self
    {
        return new self($this->threadsHidden, $this->threadsRejected, $this->replies, $this->comments, $this->reviews, $reports);
    }

    public function plus(self $other): self
    {
        return new self(
            $this->threadsHidden + $other->threadsHidden,
            $this->threadsRejected + $other->threadsRejected,
            $this->replies + $other->replies,
            $this->comments + $other->comments,
            $this->reviews + $other->reviews,
            $this->reports + $other->reports,
        );
    }

    /**
     * @return array{threads_hidden: int, threads_rejected: int, replies: int, comments: int, reviews: int, reports: int}
     */
    public function toArray(): array
    {
        return [
            'threads_hidden' => $this->threadsHidden,
            'threads_rejected' => $this->threadsRejected,
            'replies' => $this->replies,
            'comments' => $this->comments,
            'reviews' => $this->reviews,
            'reports' => $this->reports,
        ];
    }

    /**
     * In words: "hid 3 threads, removed 14 replies and rejected 2 comments". Empty when nothing came down.
     */
    public function summary(): string
    {
        $parts = array_values(array_filter([
            $this->threadsHidden > 0 ? 'hid '.$this->count($this->threadsHidden, 'thread') : null,
            $this->threadsRejected > 0 ? 'rejected '.$this->count($this->threadsRejected, 'thread').' waiting for review' : null,
            $this->replies > 0 ? 'removed '.$this->count($this->replies, 'reply', 'replies') : null,
            $this->comments > 0 ? 'rejected '.$this->count($this->comments, 'comment') : null,
            $this->reviews > 0 ? 'rejected '.$this->count($this->reviews, 'review') : null,
        ]));

        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }

        return implode(', ', array_slice($parts, 0, -1)).' and '.end($parts);
    }

    private function count(int $number, string $singular, ?string $plural = null): string
    {
        return $number.' '.($number === 1 ? $singular : ($plural ?? Str::plural($singular)));
    }
}

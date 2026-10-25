<?php

namespace Tests\Feature\Moderation;

use App\Support\Moderation\ContentTakenDown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a clean-up says it did, in numbers and in words (docs/moderation.md).
 */
class ContentTakenDownTest extends TestCase
{
    /**
     * @return array<string, array{ContentTakenDown, string}>
     */
    public static function summaries(): array
    {
        return [
            'nothing' => [new ContentTakenDown, ''],
            'one thread hidden' => [new ContentTakenDown(threadsHidden: 1), 'hid 1 thread'],
            'threads hidden' => [new ContentTakenDown(threadsHidden: 3), 'hid 3 threads'],
            'one thread rejected' => [new ContentTakenDown(threadsRejected: 1), 'rejected 1 thread waiting for review'],
            'threads rejected' => [new ContentTakenDown(threadsRejected: 2), 'rejected 2 threads waiting for review'],
            'one reply' => [new ContentTakenDown(replies: 1), 'removed 1 reply'],
            'replies' => [new ContentTakenDown(replies: 14), 'removed 14 replies'],
            'one comment' => [new ContentTakenDown(comments: 1), 'rejected 1 comment'],
            'comments' => [new ContentTakenDown(comments: 2), 'rejected 2 comments'],
            'one review' => [new ContentTakenDown(reviews: 1), 'rejected 1 review'],
            'reviews' => [new ContentTakenDown(reviews: 5), 'rejected 5 reviews'],
            'two kinds' => [new ContentTakenDown(threadsHidden: 3, replies: 14), 'hid 3 threads and removed 14 replies'],
            'three kinds' => [new ContentTakenDown(threadsHidden: 3, replies: 14, comments: 2), 'hid 3 threads, removed 14 replies and rejected 2 comments'],
            'every kind' => [
                new ContentTakenDown(1, 1, 1, 1, 1),
                'hid 1 thread, rejected 1 thread waiting for review, removed 1 reply, rejected 1 comment and rejected 1 review',
            ],
            'the reports closed are not worth saying' => [new ContentTakenDown(replies: 2, reports: 9), 'removed 2 replies'],
        ];
    }

    #[Test]
    #[DataProvider('summaries')]
    public function it_says_what_came_down_in_words(ContentTakenDown $taken, string $summary): void
    {
        $this->assertSame($summary, $taken->summary());
    }

    #[Test]
    public function it_is_empty_only_when_no_content_came_down(): void
    {
        $this->assertTrue((new ContentTakenDown)->isEmpty());
        $this->assertTrue((new ContentTakenDown(reports: 4))->isEmpty(), 'closing reports is not taking anything down');

        foreach (['threadsHidden', 'threadsRejected', 'replies', 'comments', 'reviews'] as $kind) {
            $this->assertFalse((new ContentTakenDown(...[$kind => 1]))->isEmpty(), $kind);
        }
    }

    #[Test]
    public function the_numbers_add_up_and_survive_the_round_trip_through_the_history(): void
    {
        $one = new ContentTakenDown(1, 2, 3, 4, 5, 6);
        $two = new ContentTakenDown(10, 20, 30, 40, 50, 60);

        $sum = $one->plus($two);

        $this->assertSame(['threads_hidden' => 11, 'threads_rejected' => 22, 'replies' => 33, 'comments' => 44, 'reviews' => 55, 'reports' => 66], $sum->toArray());
        $this->assertSame(165, $sum->total());
        $this->assertEquals($sum, ContentTakenDown::fromDetails($sum->toArray()));
        $this->assertEquals(new ContentTakenDown, ContentTakenDown::fromDetails(null));
        $this->assertSame(7, $one->withReports(7)->reports);
        $this->assertSame(1, $one->withReports(7)->threadsHidden);
    }
}

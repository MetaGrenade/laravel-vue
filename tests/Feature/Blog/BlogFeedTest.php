<?php

namespace Tests\Feature\Blog;

use App\Models\Blog;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BlogFeedTest extends TestCase
{
    public function test_feed_returns_atom_xml_with_latest_published_posts(): void
    {
        $olderPost = Blog::factory()->published()->create([
            'published_at' => Carbon::now()->subDays(5),
        ]);

        $newerPost = Blog::factory()->published()->create([
            'published_at' => Carbon::now()->subMinutes(5),
        ]);

        $scheduledPost = Blog::factory()->scheduled()->create([
            'scheduled_for' => Carbon::now()->subMinutes(30),
        ]);
        $expectedScheduledPublishedAt = $scheduledPost->scheduled_for->copy();

        $response = $this->get(route('blogs.feed'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8');

        $this->assertDatabaseHas('blogs', [
            'id' => $scheduledPost->id,
            'status' => 'published',
            'published_at' => $expectedScheduledPublishedAt,
        ]);

        $xml = simplexml_load_string($response->getContent());

        $this->assertNotFalse($xml, 'Feed response should be valid XML.');
        $this->assertSame('feed', $xml->getName());
        $this->assertSame(route('blogs.feed'), (string) $xml->id);
        $this->assertCount(3, $xml->entry);

        $firstEntry = $xml->entry[0];
        $this->assertSame($newerPost->title, (string) $firstEntry->title);
        $this->assertSame(route('blogs.view', ['slug' => $newerPost->slug]), (string) $firstEntry->link['href']);
        $this->assertSame($newerPost->excerpt, trim((string) $firstEntry->summary));
        $this->assertSame($newerPost->published_at->toAtomString(), (string) $firstEntry->published);

        $secondEntry = $xml->entry[1];
        $this->assertSame(route('blogs.view', ['slug' => $scheduledPost->slug]), (string) $secondEntry->link['href']);
        $this->assertSame(
            $expectedScheduledPublishedAt->toAtomString(),
            (string) $secondEntry->published,
        );

        $thirdEntry = $xml->entry[2];
        $this->assertSame(route('blogs.view', ['slug' => $olderPost->slug]), (string) $thirdEntry->link['href']);
    }
}

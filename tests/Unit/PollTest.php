<?php

namespace Tests\Unit;

use App\Models\Poll;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PollTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_open_requires_published_status(): void
    {
        $poll = Poll::factory()->create(['status' => 'draft']);

        $this->assertFalse($poll->isOpen());
    }

    public function test_is_open_respects_start_and_end_windows(): void
    {
        $futurePoll = Poll::factory()->published()->create(['starts_at' => now()->addHour()]);
        $pastPoll = Poll::factory()->published()->create(['ends_at' => now()->subHour()]);
        $openPoll = Poll::factory()->published()->create(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);

        $this->assertFalse($futurePoll->isOpen());
        $this->assertFalse($pastPoll->isOpen());
        $this->assertTrue($openPoll->isOpen());
    }

    public function test_is_open_false_for_closed_polls(): void
    {
        $poll = Poll::factory()->closed()->create();

        $this->assertFalse($poll->isOpen());
    }
}

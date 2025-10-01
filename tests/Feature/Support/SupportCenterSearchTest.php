<?php

namespace Tests\Feature\Support;

use App\Models\Faq;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SupportCenterSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_center_filters_results_and_preserves_query_across_pagination(): void
    {
        $user = User::factory()->create();

        Carbon::setTestNow('2024-01-01 00:00:00');
        SupportTicket::create([
            'user_id' => $user->id,
            'subject' => 'General question',
            'body' => 'General help needed.',
            'status' => 'open',
            'priority' => 'low',
        ]);

        Carbon::setTestNow('2024-01-01 00:01:00');
        $olderInvoiceTicket = SupportTicket::create([
            'user_id' => $user->id,
            'subject' => 'Invoice issue',
            'body' => 'Problem with invoice delivery.',
            'status' => 'open',
            'priority' => 'medium',
        ]);

        Carbon::setTestNow('2024-01-01 00:02:00');
        $newerInvoiceTicket = SupportTicket::create([
            'user_id' => $user->id,
            'subject' => 'Invoice follow-up',
            'body' => 'Follow-up question about invoice.',
            'status' => 'open',
            'priority' => 'high',
        ]);

        Carbon::setTestNow();

        $firstFaq = Faq::create([
            'question' => 'Billing FAQ one',
            'answer' => 'Details about billing.',
            'order' => 1,
            'published' => true,
        ]);

        $secondFaq = Faq::create([
            'question' => 'Billing FAQ two',
            'answer' => 'More billing information.',
            'order' => 2,
            'published' => true,
        ]);

        Faq::create([
            'question' => 'Account FAQ',
            'answer' => 'Information about accounts.',
            'order' => 3,
            'published' => true,
        ]);

        Faq::create([
            'question' => 'Hidden billing FAQ',
            'answer' => 'Should not be visible.',
            'order' => 4,
            'published' => false,
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('support', [
                'tickets_search' => 'invoice',
                'tickets_per_page' => 1,
                'tickets_page' => 2,
                'faqs_search' => 'billing',
                'faqs_per_page' => 1,
                'faqs_page' => 2,
            ]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Support')
            ->where('tickets.meta.current_page', 2)
            ->where('tickets.meta.total', 2)
            ->where('tickets.data', function ($data) use ($olderInvoiceTicket, $newerInvoiceTicket) {
                $ids = collect($data)->pluck('id');

                return $ids->contains($olderInvoiceTicket->id)
                    && !$ids->contains($newerInvoiceTicket->id);
            })
            ->where('tickets.links.prev', function ($url) {
                return is_string($url)
                    && str_contains($url, 'tickets_search=invoice')
                    && str_contains($url, 'faqs_search=billing');
            })
            ->where('tickets.links.first', function ($url) {
                return is_string($url)
                    && str_contains($url, 'tickets_search=invoice')
                    && str_contains($url, 'faqs_search=billing');
            })
            ->where('faqs.meta.current_page', 2)
            ->where('faqs.meta.total', 2)
            ->where('faqs.data', function ($data) use ($secondFaq, $firstFaq) {
                $ids = collect($data)->pluck('id');

                return $ids->contains($secondFaq->id)
                    && !$ids->contains($firstFaq->id);
            })
            ->where('faqs.links.prev', function ($url) {
                return is_string($url)
                    && str_contains($url, 'tickets_search=invoice')
                    && str_contains($url, 'faqs_search=billing');
            })
            ->where('faqs.links.first', function ($url) {
                return is_string($url)
                    && str_contains($url, 'tickets_search=invoice')
                    && str_contains($url, 'faqs_search=billing');
            }));
    }
}

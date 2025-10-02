<?php

namespace Tests\Feature\Support;

use App\Models\Faq;
use App\Models\FaqCategory;
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

        $billingCategory = FaqCategory::create([
            'name' => 'Billing',
            'slug' => 'billing',
            'description' => 'Answers about billing and invoices.',
            'order' => 1,
        ]);

        $accountCategory = FaqCategory::create([
            'name' => 'Account',
            'slug' => 'account',
            'description' => 'Profile and account management help.',
            'order' => 2,
        ]);

        $firstFaq = Faq::create([
            'faq_category_id' => $billingCategory->id,
            'question' => 'Billing FAQ one',
            'answer' => 'Details about billing.',
            'order' => 1,
            'published' => true,
        ]);

        $secondFaq = Faq::create([
            'faq_category_id' => $billingCategory->id,
            'question' => 'Billing FAQ two',
            'answer' => 'More billing information.',
            'order' => 2,
            'published' => true,
        ]);

        Faq::create([
            'faq_category_id' => $accountCategory->id,
            'question' => 'Account FAQ',
            'answer' => 'Information about accounts.',
            'order' => 3,
            'published' => true,
        ]);

        Faq::create([
            'faq_category_id' => $billingCategory->id,
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
                'faq_category_id' => $billingCategory->id,
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
            ->where('tickets.links.prev', function ($url) use ($billingCategory) {
                return is_string($url)
                    && str_contains($url, 'tickets_search=invoice')
                    && str_contains($url, 'faqs_search=billing')
                    && str_contains($url, 'faq_category_id=' . $billingCategory->id);
            })
            ->where('tickets.links.first', function ($url) use ($billingCategory) {
                return is_string($url)
                    && str_contains($url, 'tickets_search=invoice')
                    && str_contains($url, 'faqs_search=billing')
                    && str_contains($url, 'faq_category_id=' . $billingCategory->id);
            })
            ->where('faqs.filters.selectedCategoryId', $billingCategory->id)
            ->where('faqs.filters.search', 'billing')
            ->where('faqs.matchingCount', 2)
            ->where('faqs.groups', function ($groups) use ($billingCategory, $firstFaq, $secondFaq) {
                $collection = collect($groups);

                if ($collection->count() !== 1) {
                    return false;
                }

                $group = $collection->first();
                $categoryId = $group['category']['id'] ?? null;

                if ($categoryId !== $billingCategory->id) {
                    return false;
                }

                $ids = collect($group['faqs'])->pluck('id');

                return $ids->contains($firstFaq->id)
                    && $ids->contains($secondFaq->id);
            }));
    }
}

<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\AutomodRule;
use App\Models\BillingInvoice;
use App\Models\BillingWebhookCall;
use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\Coupon;
use App\Models\Faq;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\Poll;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\SearchQuery;
use App\Models\SubscriptionPlan;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Database\Seeder;

/**
 * What the signed-in accessibility audit opens (docs/accessibility.md): the records of the smoke suite (a member, an administrator, the
 * public records) and a few of everything that a list or a table in the member's pages and the admin area shows, so that a table has rows
 * and a form is the one for a record that exists. Each is the first of its kind, so the audit can name it by number: /acp/polls/1/edit.
 * Nothing here is demo content for a person using the site.
 */
class AccessibilitySignedInFlowSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SmokeFlowSeeder::class);

        $member = User::where('email', 'avery@example.com')->firstOrFail();
        $product = Product::where('slug', 'hoodie')->firstOrFail();

        SupportTicket::factory()->count(2)->create(['user_id' => $member->id]);
        Order::factory()->forUser($member)->paid()->count(2)->create();
        WishlistItem::factory()->create(['user_id' => $member->id, 'product_id' => $product->id]);
        BlogComment::factory()->count(3)->create(['blog_id' => Blog::firstOrFail()->id]);
        Poll::factory()->published()->count(2)->create();
        Coupon::factory()->percent('10')->count(2)->create();
        GiftCard::factory()->worth('25.00')->withHistory()->count(2)->create();
        ProductReview::factory()->pending()->count(2)->create(['product_id' => $product->id]);
        SubscriptionPlan::factory()->count(2)->create();
        BillingInvoice::factory()->count(2)->create(['user_id' => $member->id]);
        BillingWebhookCall::factory()->count(2)->create();
        AuditLog::factory()->count(5)->create();
        SearchQuery::factory()->count(5)->create();
        AutomodRule::factory()->words(['spam'])->count(2)->create();
        Faq::factory()->count(2)->create();
        User::factory()->count(6)->create();
    }
}

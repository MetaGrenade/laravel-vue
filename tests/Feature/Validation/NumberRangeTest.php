<?php

namespace Tests\Feature\Validation;

use App\Http\Requests\Admin\FaqCategoryRequest;
use App\Http\Requests\Admin\StoreFaqRequest;
use App\Http\Requests\Admin\StoreTokenRequest;
use App\Http\Requests\Admin\UpdateFaqRequest;
use App\Http\Requests\Admin\UpdateTokenRequest;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Support\ChecksFormRules;
use Tests\TestCase;

/**
 * A number that is saved in a column has to be refused when the column cannot hold it, with a message the form can show.
 *
 * SQLite keeps any 64-bit number in any integer column, so a number that is too big is saved there and the tests never see it. MySQL,
 * MariaDB and PostgreSQL refuse it ("Out of range value"), which reaches the person as a 500 page. An `int` column holds up to
 * 2,147,483,647 on all three (PostgreSQL's `integer`, which is what an unsigned column becomes there, holds no more).
 */
class NumberRangeTest extends TestCase
{
    use ChecksFormRules;
    use RefreshDatabase;

    /** What a signed 32 bit `int` column holds, at both ends. */
    private const INT_MIN = -2147483648;

    private const INT_MAX = 2147483647;

    /**
     * @return iterable<string, array{class-string<FormRequest>, string, int, int}>
     */
    public static function fields(): iterable
    {
        yield 'FAQ, new: order' => [StoreFaqRequest::class, 'order', self::INT_MIN, self::INT_MAX];
        yield 'FAQ, changed: order' => [UpdateFaqRequest::class, 'order', self::INT_MIN, self::INT_MAX];
        yield 'FAQ category: order' => [FaqCategoryRequest::class, 'order', self::INT_MIN, self::INT_MAX];
        // A quota is at least one request (that was already so), and its column is an unsigned integer: 4,294,967,295 on MySQL and MariaDB,
        // 2,147,483,647 on PostgreSQL. The smaller of the two is what a site on either can keep.
        yield 'token, new: hourly quota' => [StoreTokenRequest::class, 'hourly_quota', 1, self::INT_MAX];
        yield 'token, new: daily quota' => [StoreTokenRequest::class, 'daily_quota', 1, self::INT_MAX];
        yield 'token, changed: hourly quota' => [UpdateTokenRequest::class, 'hourly_quota', 1, self::INT_MAX];
        yield 'token, changed: daily quota' => [UpdateTokenRequest::class, 'daily_quota', 1, self::INT_MAX];
    }

    /**
     * @param  class-string<FormRequest>  $class
     */
    #[DataProvider('fields')]
    public function test_a_number_takes_what_its_column_holds_and_not_one_more(string $class, string $field, int $lowest, int $highest): void
    {
        foreach ([$lowest, $highest] as $limit) {
            $this->assertTrue($this->errorsFor($class, [], $field, $limit)->isEmpty(), "{$limit} was refused");
        }

        foreach ([[$lowest - 1, $lowest], [$highest + 1, $highest]] as [$value, $limit]) {
            $errors = $this->errorsFor($class, [], $field, $value);

            $this->assertNotEmpty($errors, "{$value} was accepted");
            $this->assertStringContainsString((string) $limit, $errors[0], 'the message does not say what the field can be');
        }
    }

    public function test_a_number_sent_by_a_form_as_text_is_held_to_the_same_limit(): void
    {
        // A browser sends every value as text.
        $this->assertTrue($this->errorsFor(StoreFaqRequest::class, [], 'order', (string) self::INT_MAX)->isEmpty());
        $this->assertNotEmpty($this->errorsFor(StoreFaqRequest::class, [], 'order', (string) (self::INT_MAX + 1)));
        $this->assertNotEmpty($this->errorsFor(StoreFaqRequest::class, [], 'order', '9223372036854775807'));
    }

    public function test_a_faq_with_an_order_too_big_for_its_column_is_refused_and_nothing_is_saved(): void
    {
        $this->actingAsAdmin();
        $category = FaqCategory::create(['name' => 'Probe', 'slug' => 'probe', 'order' => 1]);
        $payload = ['faq_category_id' => $category->id, 'question' => 'Why?', 'answer' => 'Because.', 'published' => true];

        $this->post(route('acp.support.faqs.store'), $payload + ['order' => self::INT_MAX + 1])
            ->assertSessionHasErrors('order');

        $this->assertSame(0, Faq::count());

        $this->post(route('acp.support.faqs.store'), $payload + ['order' => self::INT_MAX])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(self::INT_MAX, Faq::firstOrFail()->order);
    }

    public function test_a_faq_category_with_an_order_too_big_for_its_column_is_refused_when_it_is_made_and_when_it_is_changed(): void
    {
        $this->actingAsAdmin();
        $before = FaqCategory::count();

        $this->post(route('acp.support.faq-categories.store'), ['name' => 'Too far', 'order' => self::INT_MAX + 1])
            ->assertSessionHasErrors('order');

        $this->assertSame($before, FaqCategory::count());

        $category = FaqCategory::create(['name' => 'Probe', 'slug' => 'probe', 'order' => 1]);

        $this->put(route('acp.support.faq-categories.update', $category), ['name' => 'Probe', 'slug' => 'probe', 'order' => self::INT_MIN - 1])
            ->assertSessionHasErrors('order');

        $this->assertSame(1, $category->fresh()->order);
    }

    public function test_a_token_with_a_quota_too_big_for_its_column_is_refused_and_nothing_is_saved(): void
    {
        $this->actingAsAdmin();
        $owner = User::factory()->create();
        $payload = ['name' => 'Integration', 'user_id' => $owner->id, 'abilities' => ['profile:read'], 'expires_at' => null];

        $this->post(route('acp.tokens.store'), $payload + ['hourly_quota' => self::INT_MAX + 1, 'daily_quota' => null])
            ->assertSessionHasErrors('hourly_quota');
        $this->post(route('acp.tokens.store'), $payload + ['hourly_quota' => null, 'daily_quota' => self::INT_MAX + 1])
            ->assertSessionHasErrors('daily_quota');

        $this->assertSame(0, PersonalAccessToken::count());

        $this->post(route('acp.tokens.store'), $payload + ['hourly_quota' => self::INT_MAX, 'daily_quota' => self::INT_MAX])
            ->assertSessionDoesntHaveErrors();

        $token = PersonalAccessToken::firstOrFail();
        $this->assertSame(self::INT_MAX, $token->hourly_quota);
        $this->assertSame(self::INT_MAX, $token->daily_quota);
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'admin']));
        $this->actingAs($user);

        return $user;
    }
}

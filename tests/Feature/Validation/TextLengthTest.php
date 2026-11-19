<?php

namespace Tests\Feature\Validation;

use App\Http\Requests\Admin\BlogRequest;
use App\Http\Requests\Admin\PollRequest;
use App\Http\Requests\Admin\StoreFaqRequest;
use App\Http\Requests\Admin\StoreForumBoardRequest;
use App\Http\Requests\Admin\StoreForumCategoryRequest;
use App\Http\Requests\Admin\StorePermissionRequest;
use App\Http\Requests\Admin\StoreSupportResponseTemplateRequest;
use App\Http\Requests\Admin\StoreSupportTicketRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateDataExportRequest;
use App\Http\Requests\Admin\UpdateFaqRequest;
use App\Http\Requests\Admin\UpdateForumBoardRequest;
use App\Http\Requests\Admin\UpdateForumCategoryRequest;
use App\Http\Requests\Admin\UpdatePermissionRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Http\Requests\Admin\UpdateSupportResponseTemplateRequest;
use App\Http\Requests\Admin\UpdateSupportTicketRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Http\Requests\StorePublicSupportTicketRequest;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\User;
use App\Support\OpenApi\Specification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\ChecksFormRules;
use Tests\TestCase;

/**
 * A field that is saved in a column has to be refused when it is longer than the column holds, with a message the form can show.
 *
 * SQLite does not care how long a `varchar(255)` is, so a value that is too long is saved there and the tests never see it. MySQL, MariaDB
 * and PostgreSQL refuse it with "Data too long", which reaches the person as a 500 page. A `text` column holds 65,535 bytes on MySQL and
 * MariaDB: 16,000 characters is what it is sure to hold, whatever the characters are (four bytes at most each).
 */
class TextLengthTest extends TestCase
{
    use ChecksFormRules;
    use RefreshDatabase;

    /** What a `varchar(255)` holds. */
    private const VARCHAR = 255;

    /** What a `text` column is sure to hold (65,535 bytes at four bytes a character, rounded down). */
    private const TEXT = 16000;

    /**
     * @return iterable<string, array{class-string<FormRequest>, array<string, object>, string, int}>
     */
    public static function fields(): iterable
    {
        $id = ['id' => 1];

        // varchar(255)
        yield 'forum board, new: description' => [StoreForumBoardRequest::class, [], 'description', self::VARCHAR];
        yield 'forum board, changed: description' => [UpdateForumBoardRequest::class, ['board' => (object) $id], 'description', self::VARCHAR];
        yield 'forum category, new: description' => [StoreForumCategoryRequest::class, [], 'description', self::VARCHAR];
        yield 'forum category, changed: description' => [UpdateForumCategoryRequest::class, ['category' => (object) $id], 'description', self::VARCHAR];
        yield 'permission, new: name' => [StorePermissionRequest::class, [], 'name', self::VARCHAR];
        yield 'permission, changed: name' => [UpdatePermissionRequest::class, ['permission' => (object) $id], 'name', self::VARCHAR];
        yield 'role, changed: name' => [UpdateRoleRequest::class, ['role' => (object) $id], 'name', self::VARCHAR];
        // The `email` rule checks the shape and not the length: addresses of 268 and 312 characters pass it.
        yield 'member (staff), new: email' => [StoreUserRequest::class, [], 'email', self::VARCHAR];
        yield 'member (staff), changed: email' => [UpdateUserRequest::class, ['user' => (object) $id], 'email', self::VARCHAR];

        // text
        yield 'poll: description' => [PollRequest::class, [], 'description', self::TEXT];
        yield 'blog post: excerpt' => [BlogRequest::class, [], 'excerpt', self::TEXT];
        yield 'blog post: author bio' => [BlogRequest::class, [], 'author.profile_bio', self::TEXT];
        yield 'FAQ, new: answer' => [StoreFaqRequest::class, [], 'answer', self::TEXT];
        yield 'FAQ, changed: answer' => [UpdateFaqRequest::class, [], 'answer', self::TEXT];
        yield 'ticket reply template, new: body' => [StoreSupportResponseTemplateRequest::class, [], 'body', self::TEXT];
        yield 'ticket reply template, changed: body' => [UpdateSupportResponseTemplateRequest::class, [], 'body', self::TEXT];
        yield 'ticket (staff), new: body' => [StoreSupportTicketRequest::class, [], 'body', self::TEXT];
        yield 'ticket (staff), changed: body' => [UpdateSupportTicketRequest::class, [], 'body', self::TEXT];
        yield 'ticket (member and API): body' => [StorePublicSupportTicketRequest::class, [], 'body', self::TEXT];
        yield 'member (staff), new: bio' => [StoreUserRequest::class, [], 'profile_bio', self::TEXT];
        yield 'member (staff), changed: bio' => [UpdateUserRequest::class, ['user' => (object) $id], 'profile_bio', self::TEXT];
        yield 'profile settings: bio' => [ProfileUpdateRequest::class, [], 'profile_bio', self::TEXT];
        yield 'data export: failure reason' => [UpdateDataExportRequest::class, [], 'failure_reason', self::TEXT];
    }

    /**
     * @param  class-string<FormRequest>  $class
     * @param  array<string, object>  $route
     */
    #[DataProvider('fields')]
    public function test_a_field_takes_what_its_column_holds_and_not_one_character_more(string $class, array $route, string $field, int $limit): void
    {
        $this->assertTrue($this->errorsFor($class, $route, $field, $this->valueOf($field, $limit))->isEmpty(), "{$limit} characters were refused");

        $errors = $this->errorsFor($class, $route, $field, $this->valueOf($field, $limit + 1));

        $this->assertNotEmpty($errors, 'one character more than the column holds was accepted');
        $this->assertStringContainsString((string) $limit, $errors[0], 'the message does not say how long the field can be');
    }

    public function test_the_length_is_counted_in_characters_and_not_in_bytes(): void
    {
        // A `varchar(255)` holds 255 characters, however many bytes they take, and so does `max:255`.
        $this->assertTrue($this->errorsFor(StoreForumBoardRequest::class, [], 'description', str_repeat('é', 255))->isEmpty());
        $this->assertNotEmpty($this->errorsFor(StoreForumBoardRequest::class, [], 'description', str_repeat('é', 256)));
    }

    public function test_the_api_reference_says_how_long_the_first_message_of_a_ticket_can_be(): void
    {
        $schema = Specification::toArray()['paths']['/v1/support/tickets']['post']['requestBody']['content']['multipart/form-data']['schema'];

        $this->assertSame(self::TEXT, $schema['properties']['body']['maxLength']);
        $this->assertContains('max:'.self::TEXT, (new StorePublicSupportTicketRequest)->rules()['body']);
    }

    public function test_a_forum_board_description_that_is_too_long_is_refused_and_nothing_is_saved(): void
    {
        $this->actingAsAdmin();
        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'position' => 1]);

        $this->post(route('acp.forums.boards.store'), [
            'forum_category_id' => $category->id,
            'title' => 'Plans',
            'description' => str_repeat('a', 256),
        ])->assertSessionHasErrors('description');

        $this->assertSame(0, ForumBoard::count());

        $this->post(route('acp.forums.boards.store'), [
            'forum_category_id' => $category->id,
            'title' => 'Plans',
            'description' => str_repeat('a', 255),
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame(255, mb_strlen((string) ForumBoard::firstOrFail()->description));
    }

    public function test_a_forum_category_description_that_is_too_long_is_refused_when_it_is_changed(): void
    {
        $this->actingAsAdmin();
        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'Keep', 'position' => 1]);

        $this->put(route('acp.forums.categories.update', $category), [
            'title' => 'General',
            'description' => str_repeat('a', 256),
        ])->assertSessionHasErrors('description');

        $this->assertSame('Keep', $category->fresh()->description);
    }

    public function test_a_permission_name_that_is_too_long_is_refused_and_nothing_is_saved(): void
    {
        $this->actingAsAdmin();
        $before = Permission::count();

        $this->post(route('acp.acl.permissions.store'), ['name' => str_repeat('a', 256), 'guard_name' => 'web'])
            ->assertSessionHasErrors('name');

        $this->assertSame($before, Permission::count());
    }

    /**
     * A value of exactly this many characters that is good in every other way: an email address has to be one (a 64 character name, then
     * labels of at most 63 characters), or the shape would be refused and the length never reached.
     */
    private function valueOf(string $field, int $length): string
    {
        if ($field !== 'email') {
            return str_repeat('a', $length);
        }

        // 64 + "@" + the labels + ".com", with a dot after every 62 characters of the labels (the check counts a label's dot with it, so
        // 63 characters and a dot are refused) and none of them empty.
        $labels = str_repeat('b', $length - 64 - 1 - 4);

        for ($at = 62; $at < strlen($labels) - 1; $at += 63) {
            $labels[$at] = '.';
        }

        $value = str_repeat('a', 64).'@'.$labels.'.com';

        $this->assertSame($length, strlen($value), 'the test could not build an address of that length');

        return $value;
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'admin']));
        $this->actingAs($user);

        return $user;
    }
}

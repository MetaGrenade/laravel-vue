<?php

namespace Tests\Unit\Http;

use App\Support\Http\RequestText;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What text a request gives for a name, whatever shape the query string or the body sent it in (App\Support\Http\RequestText).
 */
class RequestTextTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, string}> what the request carries, and the text read from `name`
     */
    public static function shapes(): array
    {
        return [
            'a string, as it was sent' => [['name' => '  Ada  '], '  Ada  '],
            'an empty string, which was sent' => [['name' => ''], ''],
            'a string that looks like a number' => [['name' => '007'], '007'],
            'nothing sent: the default' => [[], 'fallback'],
            'a list: the default, as if it was not sent' => [['name' => ['a', 'b']], 'fallback'],
            'an empty list: the default' => [['name' => []], 'fallback'],
            'a keyed list: the default' => [['name' => ['key' => 'a']], 'fallback'],
            'a list of lists: the default' => [['name' => [['a']]], 'fallback'],
        ];
    }

    /**
     * @param  array<string, mixed>  $sent
     */
    #[Test]
    #[DataProvider('shapes')]
    public function the_text_of_the_query_string(array $sent, string $expected): void
    {
        $this->assertSame($expected, RequestText::query(Request::create('/page', 'GET', $sent), 'name', 'fallback'));
    }

    /**
     * @param  array<string, mixed>  $sent
     */
    #[Test]
    #[DataProvider('shapes')]
    public function the_text_of_a_form(array $sent, string $expected): void
    {
        $this->assertSame($expected, RequestText::input(Request::create('/page', 'POST', $sent), 'name', 'fallback'));
    }

    #[Test]
    public function the_default_is_nothing_unless_one_is_given(): void
    {
        $request = Request::create('/page', 'GET', ['name' => ['a']]);

        $this->assertSame('', RequestText::query($request, 'name'));
        $this->assertSame('', RequestText::input($request, 'name'));
    }

    #[Test]
    public function a_number_a_boolean_or_nothing_in_a_json_body_is_read_as_the_cast_would(): void
    {
        $request = Request::create('/page', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"count":5,"ratio":1.5,"yes":true,"no":false,"nothing":null}');

        $this->assertSame('5', RequestText::input($request, 'count', 'fallback'));
        $this->assertSame('1.5', RequestText::input($request, 'ratio', 'fallback'));
        $this->assertSame('1', RequestText::input($request, 'yes', 'fallback'));
        $this->assertSame('', RequestText::input($request, 'no', 'fallback'));
        $this->assertSame('fallback', RequestText::input($request, 'nothing', 'fallback'));
    }

    #[Test]
    public function the_query_string_and_the_body_are_not_the_same_place(): void
    {
        $request = Request::create('/page?from=query', 'POST', ['from' => 'body']);

        $this->assertSame('query', RequestText::query($request, 'from'));
        $this->assertSame('body', RequestText::input($request, 'from'));
        $this->assertSame('', RequestText::query($request, 'only_in_the_body'));
    }
}

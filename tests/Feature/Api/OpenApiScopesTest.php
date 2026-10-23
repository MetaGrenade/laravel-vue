<?php

namespace Tests\Feature\Api;

use App\Support\Api\Scopes;
use App\Support\OpenApi\Specification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The API documentation says which scope each operation needs, read from the routes so it cannot drift from what they enforce.
 */
class OpenApiScopesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array<string, mixed>>
     */
    private function operations(): array
    {
        $operations = [];

        foreach (Specification::toArray()['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $operations[strtoupper($method).' '.$path] = $operation;
            }
        }

        return $operations;
    }

    #[Test]
    public function every_operation_that_takes_a_key_says_which_scope_it_needs(): void
    {
        $withoutOne = [];

        foreach ($this->operations() as $name => $operation) {
            if (($operation['security'] ?? null) !== null && ! isset($operation['x-required-scope']) && $name !== 'DELETE /v1/auth/token') {
                $withoutOne[] = $name;
            }
        }

        $this->assertSame([], $withoutOne, 'documented as needing a key, but no route scope was found for it');
    }

    #[Test]
    public function the_scopes_documented_are_the_sites_own(): void
    {
        $scopes = app(Scopes::class)->keys();
        $documented = [];

        foreach ($this->operations() as $operation) {
            array_push($documented, ...(array) ($operation['x-required-scope'] ?? []));
        }

        $this->assertSame([], array_values(array_diff($documented, $scopes)));
        $this->assertSame([], array_values(array_diff($scopes, $documented)), 'a scope the documentation never mentions');
    }

    #[Test]
    public function an_operation_names_its_scope_in_words_and_may_answer_with_a_403(): void
    {
        $operations = $this->operations();

        $this->assertSame('profile:read', $operations['GET /v1/profile']['x-required-scope']);
        $this->assertStringContainsString('Needs the `profile:read` scope.', $operations['GET /v1/profile']['description']);
        $this->assertArrayHasKey('403', $operations['GET /v1/profile']['responses']);

        $this->assertSame('support:read', $operations['GET /v1/support/tickets/{ticket}']['x-required-scope']);
        $this->assertSame('support:write', $operations['POST /v1/support/tickets/{ticket}/messages']['x-required-scope']);
        $this->assertSame('forum:moderate', $operations['PATCH /v1/forum/boards/{board}/threads/{thread}/lock']['x-required-scope']);
        $this->assertSame('blog:write', $operations['POST /v1/blogs/{slug}/comments']['x-required-scope']);
    }

    #[Test]
    public function a_key_can_always_remove_itself_so_that_operation_needs_no_scope(): void
    {
        $this->assertArrayNotHasKey('x-required-scope', $this->operations()['DELETE /v1/auth/token']);
    }

    #[Test]
    public function public_operations_are_left_alone(): void
    {
        $operations = $this->operations();

        $this->assertArrayNotHasKey('x-required-scope', $operations['GET /v1/blogs']);
        $this->assertArrayNotHasKey('x-required-scope', $operations['POST /v1/auth/token']);
    }

    #[Test]
    public function the_sign_in_operation_says_what_a_key_gets_when_the_request_does_not_say(): void
    {
        config(['api.sign_in.scopes' => ['profile:read', 'polls:vote'], 'api.sign_in.expiry_days' => 30]);

        $description = Specification::toArray()['paths']['/v1/auth/token']['post']['description'];

        $this->assertStringContainsString('without it the key gets `profile:read`, `polls:vote` and lasts 30 days', $description);
        $this->assertStringContainsString('the owner is told by email', $description);

        config(['api.sign_in.scopes' => ['*'], 'api.sign_in.expiry_days' => null]);

        $description = Specification::toArray()['paths']['/v1/auth/token']['post']['description'];

        $this->assertStringContainsString('the key gets every scope (`*`) and lasts until it is revoked', $description);
    }

    #[Test]
    public function the_token_response_lists_the_abilities_of_the_key(): void
    {
        $schema = Specification::toArray()['components']['schemas']['TokenResponse'];

        $this->assertSame('array', $schema['properties']['abilities']['type']);
    }

    #[Test]
    public function the_scheme_explains_the_keys(): void
    {
        $scheme = Specification::toArray()['components']['securitySchemes']['sanctum'];

        $this->assertStringContainsString('Settings → API keys', $scheme['description']);
    }
}

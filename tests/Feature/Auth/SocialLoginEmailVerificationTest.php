<?php

namespace Tests\Feature\Auth;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\OAuth\Contracts\Provider;
use App\Support\OAuth\OAuthManager;
use App\Support\OAuth\OAuthUser;
use App\Support\OAuth\Providers\DiscordProvider;
use App\Support\OAuth\Providers\GoogleProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

/**
 * An email address from a social provider only means something if the provider says the person owns it.
 * Without that, anyone could sign in as someone else (or take their guest orders) by giving a provider their
 * address, so an address the provider has not verified is never used to recognise an account or to trust one.
 */
class SocialLoginEmailVerificationTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
    }

    private function signInWithProvider(string $email, bool $verified, string $provider = 'discord', string $id = 'discord-1'): void
    {
        $oauthUser = new OAuthUser(
            id: $id,
            nickname: 'Someone',
            name: 'Someone',
            email: $email,
            avatar: null,
            raw: [],
            emailVerified: $verified,
        );

        $driver = Mockery::mock(Provider::class);
        $driver->shouldReceive('user')->andReturn($oauthUser);

        $manager = Mockery::mock(OAuthManager::class);
        $manager->shouldReceive('driver')->with($provider)->andReturn($driver);
        $this->instance(OAuthManager::class, $manager);

        $this->get(route('oauth.callback', ['provider' => $provider]));
    }

    // --- Recognising an account by its address ---------------------------------------------------------

    #[Test]
    public function a_verified_provider_address_signs_in_to_the_account_that_has_it(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);

        $this->signInWithProvider('ada@example.test', verified: true);

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider' => 'discord', 'provider_id' => 'discord-1']);
    }

    #[Test]
    public function an_address_the_provider_has_not_verified_never_signs_in_to_an_existing_account(): void
    {
        $victim = User::factory()->create(['email' => 'ada@example.test']);

        $this->signInWithProvider('ada@example.test', verified: false);

        $this->assertAuthenticated();
        $this->assertNotSame($victim->id, auth()->id(), 'not the account whose address was typed in');
        $this->assertSame(0, SocialAccount::query()->where('user_id', $victim->id)->count(), 'and nothing was linked to it');

        $newcomer = auth()->user();
        $this->assertStringEndsWith('@oauth.local', $newcomer->email, 'the address belongs to someone else, so it is not used');
        $this->assertNull($newcomer->email_verified_at);
    }

    // --- Trusting an address on a new account ----------------------------------------------------------

    #[Test]
    public function a_verified_provider_address_makes_a_verified_account(): void
    {
        $this->signInWithProvider('new@example.test', verified: true);

        $user = User::query()->where('email', 'new@example.test')->sole();
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function an_address_the_provider_has_not_verified_makes_an_account_that_still_has_to_verify_it(): void
    {
        $this->signInWithProvider('new@example.test', verified: false);

        $user = User::query()->where('email', 'new@example.test')->sole();
        $this->assertNull($user->email_verified_at, 'the person confirms it by email like anyone else');
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function an_unverified_account_made_this_way_takes_no_guest_orders(): void
    {
        $order = Order::factory()->paid()->create(['customer_email' => 'victim@example.test']);

        $this->signInWithProvider('victim@example.test', verified: false);

        $this->assertNull($order->fresh()->user_id, 'giving a provider someone\'s address does not give you their orders');
        $this->assertNull($order->fresh()->owner_type);
    }

    #[Test]
    public function a_verified_provider_address_does_take_the_guest_orders_for_that_address(): void
    {
        $order = Order::factory()->paid()->create(['customer_email' => 'ada@example.test']);

        $this->signInWithProvider('ada@example.test', verified: true);

        $this->assertTrue($order->fresh()->isOwnedBy(User::query()->where('email', 'ada@example.test')->sole()));
    }

    #[Test]
    public function a_social_sign_in_brings_the_guests_cart_along(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);
        $product = Product::factory()->priced('10.00')->stocked(5)->create();
        $this->addToCart($product, 2);

        $this->signInWithProvider('ada@example.test', verified: true);

        $cart = Cart::query()->where('user_id', $user->id)->sole();
        $this->assertSame(2, $cart->items()->sole()->quantity);
    }

    // --- What each provider says ----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $profile
     */
    private function mapped(string $class, array $profile): OAuthUser
    {
        $provider = new $class('x', [], app('session.store'), app('url'));
        $method = new ReflectionMethod($provider, 'mapUserToObject');

        return $method->invoke($provider, $profile, [], 'token');
    }

    #[Test]
    public function google_says_whether_it_verified_the_address(): void
    {
        $this->assertTrue($this->mapped(GoogleProvider::class, ['sub' => '1', 'email' => 'a@example.test', 'email_verified' => true])->emailVerified);
        $this->assertTrue($this->mapped(GoogleProvider::class, ['sub' => '1', 'email' => 'a@example.test', 'email_verified' => 'true'])->emailVerified);
        $this->assertFalse($this->mapped(GoogleProvider::class, ['sub' => '1', 'email' => 'a@example.test', 'email_verified' => false])->emailVerified);
        $this->assertFalse($this->mapped(GoogleProvider::class, ['sub' => '1', 'email' => 'a@example.test'])->emailVerified, 'silence is not verification');
    }

    #[Test]
    public function discord_says_whether_it_verified_the_address(): void
    {
        $this->assertTrue($this->mapped(DiscordProvider::class, ['id' => '1', 'username' => 'a', 'email' => 'a@example.test', 'verified' => true])->emailVerified);
        $this->assertFalse($this->mapped(DiscordProvider::class, ['id' => '1', 'username' => 'a', 'email' => 'a@example.test', 'verified' => false])->emailVerified);
        $this->assertFalse($this->mapped(DiscordProvider::class, ['id' => '1', 'username' => 'a', 'email' => 'a@example.test'])->emailVerified);
    }
}

<?php

namespace Tests\Feature\Commerce;

use App\Models\SystemSetting;
use App\Models\User;
use App\Payments\Contracts\PaymentProvider;
use App\Payments\Exceptions\PaymentException;
use App\Payments\PaymentManager;
use App\Payments\Providers\StripeProvider;
use App\Support\OAuth\OAuthProviders;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithCommerce;
use Tests\TestCase;

class PaymentProviderSettingTest extends TestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerce();
    }

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->create()->assignRole('admin');
    }

    #[Test]
    public function the_configured_provider_is_used_by_default(): void
    {
        $manager = app(PaymentManager::class);

        $this->assertSame('stripe', $manager->activeKey());
        $this->assertInstanceOf(StripeProvider::class, $manager->active());
        $this->assertInstanceOf(PaymentProvider::class, $manager->provider('stripe'));
    }

    #[Test]
    public function an_unknown_provider_cannot_be_resolved(): void
    {
        $this->expectException(PaymentException::class);

        app(PaymentManager::class)->provider('paypal');
    }

    #[Test]
    public function a_stored_provider_that_no_longer_exists_falls_back_to_configuration(): void
    {
        SystemSetting::set(PaymentManager::SETTING_KEY, 'removed-provider');

        $this->assertSame('stripe', app(PaymentManager::class)->activeKey());
    }

    #[Test]
    public function stripe_reports_what_it_can_do(): void
    {
        $provider = app(PaymentManager::class)->provider('stripe');

        $this->assertContains('one_time_payments', $provider->capabilities());
        $this->assertContains('hosted_checkout', $provider->capabilities());
    }

    #[Test]
    public function stripe_is_only_configured_with_both_keys(): void
    {
        $provider = app(PaymentManager::class)->provider('stripe');
        $this->assertTrue($provider->isConfigured());

        config(['cashier.secret' => null]);
        $this->assertFalse($provider->isConfigured());

        config(['cashier.secret' => 'sk_test_x', 'cashier.webhook.secret' => null]);
        $this->assertFalse($provider->isConfigured(), 'without the signing secret payments could never be confirmed');
    }

    #[Test]
    public function an_administrator_sees_and_can_choose_the_provider(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('acp.system'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.commerce_provider', 'stripe')
                ->where('commerceProviders.0.key', 'stripe')
                ->where('commerceProviders.0.configured', true));

        $this->actingAs($admin)
            ->put(route('acp.system.update'), $this->settingsPayload(['commerce_provider' => 'stripe']))
            ->assertSessionHasNoErrors();

        $this->assertSame('stripe', SystemSetting::get(PaymentManager::SETTING_KEY));
    }

    #[Test]
    public function an_unknown_provider_is_rejected_by_the_settings_form(): void
    {
        $this->actingAs($this->admin())
            ->put(route('acp.system.update'), $this->settingsPayload(['commerce_provider' => 'paypal']))
            ->assertSessionHasErrors('commerce_provider');

        $this->assertNull(SystemSetting::get(PaymentManager::SETTING_KEY));
    }

    #[Test]
    public function the_settings_form_still_works_without_the_provider_field(): void
    {
        $this->actingAs($this->admin())
            ->put(route('acp.system.update'), $this->settingsPayload())
            ->assertSessionHasNoErrors();

        $this->assertNull(SystemSetting::get(PaymentManager::SETTING_KEY));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function settingsPayload(array $extra = []): array
    {
        return [
            'maintenance_mode' => false,
            'email_verification_required' => false,
            'website_sections' => ['blog' => true, 'forum' => true, 'support' => true, 'commerce' => true],
            'oauth_providers' => collect(OAuthProviders::keys())->mapWithKeys(fn ($key) => [$key => false])->all(),
            ...$extra,
        ];
    }
}

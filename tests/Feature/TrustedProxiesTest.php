<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A site behind a proxy that ends HTTPS (docs/deployment.md): the request arrives as http:// with X-Forwarded-Proto: https, and is
 * believed only from the proxies in TRUSTED_PROXIES.
 */
class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Symfony's Request keeps the trusted proxies for the whole process, and a request that trusts none leaves them as they were, so
        // a test that trusted some would hand them to every test after it.
        Request::setTrustedProxies([], -1);

        parent::tearDown();
    }

    #[Test]
    public function no_proxy_is_trusted_unless_the_site_is_told_to(): void
    {
        $this->assertNull(config('trustedproxy.proxies'));

        config(['security.hsts.enabled' => true]);

        // Anybody can send the header, so a site that trusts nobody ignores it.
        $this->get('http://localhost/', ['X-Forwarded-Proto' => 'https'])->assertHeaderMissing('Strict-Transport-Security');
    }

    #[Test]
    public function a_trusted_proxy_makes_the_site_see_https(): void
    {
        config(['security.hsts.enabled' => true, 'trustedproxy.proxies' => '*']);

        $this->get('http://localhost/', ['X-Forwarded-Proto' => 'https'])->assertHeader('Strict-Transport-Security');
        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');
    }

    #[Test]
    public function only_the_listed_proxies_are_believed(): void
    {
        config(['security.hsts.enabled' => true]);

        // The test client connects from 127.0.0.1.
        config(['trustedproxy.proxies' => '10.0.0.0/8,172.16.0.0/12']);
        $this->get('http://localhost/', ['X-Forwarded-Proto' => 'https'])->assertHeaderMissing('Strict-Transport-Security');

        config(['trustedproxy.proxies' => '10.0.0.0/8, 127.0.0.1']);
        $this->get('http://localhost/', ['X-Forwarded-Proto' => 'https'])->assertHeader('Strict-Transport-Security');

        config(['trustedproxy.proxies' => 'REMOTE_ADDR']);
        $this->get('http://localhost/', ['X-Forwarded-Proto' => 'https'])->assertHeader('Strict-Transport-Security');
    }

    #[Test]
    public function a_link_signed_as_https_works_when_it_arrives_through_a_proxy_that_is_trusted(): void
    {
        // What APP_FORCE_HTTPS does, and what a queued email does with an APP_URL that starts with https://.
        URL::forceHttps();

        $user = User::factory()->unverified()->create();
        $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->assertStringStartsWith('https://', $link);

        // The proxy opens it over https and hands PHP plain http.
        $arrives = preg_replace('#^https://#', 'http://', $link);

        $this->actingAs($user)->get($arrives, ['X-Forwarded-Proto' => 'https'])->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        config(['trustedproxy.proxies' => '*']);

        $this->actingAs($user)->get($arrives, ['X-Forwarded-Proto' => 'https'])->assertRedirect(route('dashboard', absolute: false).'?verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    #[Test]
    public function the_setting_is_read_from_the_environment_and_empty_means_none(): void
    {
        $read = function (?string $value): mixed {
            $repository = Env::getRepository();

            if ($value !== null) {
                $repository->set('TRUSTED_PROXIES', $value);
            }

            try {
                return (require config_path('trustedproxy.php'))['proxies'];
            } finally {
                $repository->clear('TRUSTED_PROXIES');
            }
        };

        $this->assertNull($read(null));
        // An empty line in .env must not read as "trust the empty list": Laravel's own choice for Forge hosts applies only to null.
        $this->assertNull($read(''));
        $this->assertSame('*', $read('*'));
        $this->assertSame('10.0.0.0/8,172.16.0.0/12', $read('10.0.0.0/8,172.16.0.0/12'));
    }
}

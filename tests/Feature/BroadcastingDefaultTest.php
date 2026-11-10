<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which broadcasting connection the site uses, from `BROADCAST_CONNECTION`, `BROADCAST_FORCE_LOG` and the three Pusher credentials
 * (`config/broadcasting.php`, docs/configuration.md, docs/realtime.md).
 *
 * The settings are read from the environment when the configuration file is loaded, and the environment of a running test cannot be
 * changed (it is read-only once loaded), so each case starts the application again with `php artisan config:show` and the settings it
 * wants. A setting a case leaves out is passed as an empty string, which is "not set" to the file and keeps this machine's own `.env`
 * from filling it in.
 */
class BroadcastingDefaultTest extends TestCase
{
    private const CREDENTIALS = ['PUSHER_APP_ID' => 'id', 'PUSHER_APP_KEY' => 'key', 'PUSHER_APP_SECRET' => 'secret'];

    /**
     * @param  array<string, string>  $credentials
     */
    private function connectionFor(string $connection, string $forceLog, array $credentials): string
    {
        $result = Process::path(base_path())->env([
            'BROADCAST_CONNECTION' => $connection,
            'BROADCAST_FORCE_LOG' => $forceLog,
            'PUSHER_APP_ID' => $credentials['PUSHER_APP_ID'] ?? '',
            'PUSHER_APP_KEY' => $credentials['PUSHER_APP_KEY'] ?? '',
            'PUSHER_APP_SECRET' => $credentials['PUSHER_APP_SECRET'] ?? '',
            // A cached configuration is read instead of the environment, so a machine that has run `config:cache` would answer for the
            // cache. This file never exists.
            'APP_CONFIG_CACHE' => storage_path('framework/testing/no-config-cache.php'),
        ])->run([PHP_BINARY, 'artisan', 'config:show', 'broadcasting.default']);

        $this->assertTrue($result->successful(), $result->errorOutput().$result->output());
        $this->assertSame(1, preg_match('/broadcasting\.default\s+\.+\s+(\S+)/', (string) preg_replace('/\e\[[0-9;]*m/', '', $result->output()), $match), $result->output());

        return $match[1];
    }

    /**
     * @return array<string, array{string, string, array<string, string>, string}>
     */
    public static function settings(): array
    {
        $all = self::CREDENTIALS;
        $two = ['PUSHER_APP_ID' => 'id', 'PUSHER_APP_KEY' => 'key'];

        return [
            // --- Forcing the log ---------------------------------------------------------------
            'forced, with pusher chosen and its credentials (what .env.example sets, on a staging site)' => ['pusher', 'true', $all, 'log'],
            'forced, with the connection left out' => ['', 'true', $all, 'log'],
            'forced, with log chosen' => ['log', 'true', $all, 'log'],
            'forced, with another real connection chosen' => ['redis', 'true', $all, 'log'],
            'forced, with pusher chosen and no credentials' => ['pusher', 'true', [], 'log'],
            'forced, written as 1' => ['pusher', '1', $all, 'log'],
            'forced, written as on' => ['pusher', 'on', $all, 'log'],

            // --- Not forcing it ----------------------------------------------------------------
            'pusher chosen, with its credentials' => ['pusher', 'false', $all, 'pusher'],
            'pusher chosen, with its credentials, and the flag left empty' => ['pusher', '', $all, 'pusher'],
            'pusher chosen, with its credentials, and a flag that is not a boolean' => ['pusher', 'perhaps', $all, 'pusher'],
            'pusher chosen, with two of its three credentials' => ['pusher', 'false', $two, 'log'],
            'pusher chosen, with no credentials' => ['pusher', 'false', [], 'log'],
            'another real connection chosen' => ['redis', 'false', $all, 'redis'],
            'the connection left out, with the credentials' => ['', 'false', $all, 'pusher'],
            'log chosen, with the credentials' => ['log', 'false', $all, 'pusher'],
            'the connection left out, with no credentials' => ['', 'false', [], 'log'],
            'log chosen, with no credentials' => ['log', 'false', [], 'log'],
        ];
    }

    /**
     * @param  array<string, string>  $credentials
     */
    #[Test]
    #[DataProvider('settings')]
    public function the_connection_follows_the_settings(string $connection, string $forceLog, array $credentials, string $expected): void
    {
        $this->assertSame($expected, $this->connectionFor($connection, $forceLog, $credentials));
    }
}

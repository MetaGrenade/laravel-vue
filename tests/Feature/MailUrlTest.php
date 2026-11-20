<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `MAIL_URL` is one address in place of `MAIL_HOST`, `MAIL_PORT` and the rest (config/mail.php, docs/configuration.md). Left empty
 * (`MAIL_URL=`, which is what a line copied from a template and not filled in looks like) it has to mean "not set": Laravel's mail
 * manager treats any `url` that is set as an address to take the transport from, finds none in an empty text and throws
 * "Unsupported mail transport []", so every email sent through `smtp` failed until the line was deleted.
 *
 * The environment is read when the configuration file is loaded and cannot be changed in a running test, so each case starts the
 * application again with the settings it wants, and asks the `smtp` mailer which server it would talk to. Nothing is sent.
 */
class MailUrlTest extends TestCase
{
    /**
     * @param  array<string, string>  $settings
     */
    private function smtpServerFor(array $settings): string
    {
        $result = Process::path(base_path())->env($settings + [
            'MAIL_MAILER' => 'smtp',
            // Nothing in this process may reach the database of the machine it runs on.
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            // A cached configuration is read instead of the environment, so a machine that has run `config:cache` would answer for the
            // cache. This file never exists.
            'APP_CONFIG_CACHE' => storage_path('framework/testing/no-config-cache.php'),
        ])->run([
            PHP_BINARY,
            '-r',
            'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
            .' try { echo (string) app("mail.manager")->mailer("smtp")->getSymfonyTransport(); }'
            .' catch (Throwable $e) { echo get_class($e).": ".$e->getMessage(); }',
        ]);

        $this->assertTrue($result->successful(), $result->errorOutput().$result->output());

        return trim($result->output());
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function settings(): array
    {
        return [
            'an empty MAIL_URL is not set: the host and the port are used' => [['MAIL_URL' => '', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '2525'], 'smtp://127.0.0.1:2525'],
            'a MAIL_URL is used in place of the host and the port' => [['MAIL_URL' => 'smtp://mail.example.test:2626', 'MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '2525'], 'smtp://mail.example.test:2626'],
        ];
    }

    /**
     * @param  array<string, string>  $settings
     */
    #[Test]
    #[DataProvider('settings')]
    public function the_smtp_mailer_takes_its_server_from_the_address_only_when_there_is_one(array $settings, string $expected): void
    {
        $this->assertSame($expected, $this->smtpServerFor($settings));
    }
}

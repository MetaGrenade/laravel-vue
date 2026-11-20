<?php

namespace Tests\Feature;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\TestCase;

/**
 * How long the site waits for an SMTP server that takes the connection and says nothing (`MAIL_TIMEOUT`, config/mail.php,
 * docs/deployment.md#files-mail-and-backups).
 *
 * `'timeout' => null` meant PHP's `default_socket_timeout`, 60 seconds: measured, a mail sent to such a server held the request for
 * 60.6 seconds, and the verification and password reset emails Laravel sends inside the request are sent that way. The server here is
 * a socket that is listening and never answers (the operating system accepts the connection and nobody reads it), so the client has
 * to give up on its own.
 *
 * Every test that could wait for ever looks at the limit before it sends, so a missing limit fails the test and does not hang it.
 */
class MailCallTimeoutTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            fclose($this->server);
        }

        parent::tearDown();
    }

    /** @return int the port of a server that accepts connections and never says a word */
    private function silentServer(): int
    {
        $this->server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($this->server, "No socket to listen on: $error");

        return (int) substr((string) strrchr((string) stream_socket_get_name($this->server, false), ':'), 1);
    }

    /** The socket the `smtp` mailer talks through, where its limit is kept. */
    private function smtpStream(): SocketStream
    {
        $transport = Mail::mailer('smtp')->getSymfonyTransport();
        $this->assertInstanceOf(EsmtpTransport::class, $transport);

        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);

        return $stream;
    }

    /**
     * What `max(0.0, (float) env('MAIL_TIMEOUT', 15)) ?: null` in config/mail.php gives for one value of the setting, with the real
     * file in a bare PHP process: only the environment given here is seen, so a `.env` on this machine cannot answer for it.
     *
     * @param  string|false  $setting  false leaves the setting out of the environment
     */
    private function timeoutFor(string|false $setting): string
    {
        $result = Process::path(base_path())->env(['MAIL_TIMEOUT' => $setting])->run([
            PHP_BINARY,
            '-r',
            // The file asks the application for a path (resource_path), so there has to be one; it is not started.
            'require "vendor/autoload.php"; Illuminate\Container\Container::setInstance(new Illuminate\Foundation\Application(getcwd()));'
            .' echo var_export((require "config/mail.php")["mailers"]["smtp"]["timeout"], true);',
        ]);

        $this->assertTrue($result->successful(), $result->errorOutput().$result->output());

        return trim($result->output());
    }

    /** @return array<string, array{string|false, string}> */
    public static function settings(): array
    {
        return [
            'not set: 15 seconds' => [false, '15.0'],
            'set to 30' => ['30', '30.0'],
            'a part of a second' => ['2.5', '2.5'],
            'empty: PHP\'s own limit' => ['', 'NULL'],
            '0 is PHP\'s own limit, not "no limit"' => ['0', 'NULL'],
            'negative is PHP\'s own limit' => ['-3', 'NULL'],
            'not a number is PHP\'s own limit' => ['soon', 'NULL'],
        ];
    }

    #[Test]
    #[DataProvider('settings')]
    public function the_limit_follows_the_setting(string|false $setting, string $expected): void
    {
        $this->assertSame($expected, $this->timeoutFor($setting));
    }

    #[Test]
    public function the_smtp_transport_is_given_the_limit_with_or_without_a_url(): void
    {
        $port = $this->silentServer();

        foreach ([[], ['url' => "smtp://127.0.0.1:$port"]] as $extra) {
            config(['mail.mailers.smtp' => array_merge(config('mail.mailers.smtp'), ['host' => '127.0.0.1', 'port' => $port, 'timeout' => 7.5], $extra)]);
            Mail::purge('smtp');

            $this->assertSame(7.5, $this->smtpStream()->getTimeout(), 'The SMTP transport does not use mail.mailers.smtp.timeout'.($extra === [] ? '.' : ' when the address is given as MAIL_URL.'));
        }
    }

    #[Test]
    public function a_server_that_never_answers_is_given_up_on_after_the_limit_in_the_environment(): void
    {
        $port = $this->silentServer();

        $started = microtime(true);
        $result = Process::path(base_path())->timeout(25)->env([
            'MAIL_TIMEOUT' => '2',
            'MAIL_MAILER' => 'smtp',
            // The address is given as one URL: it wins over whatever MAIL_HOST, MAIL_PORT and MAIL_SCHEME a `.env` here has.
            'MAIL_URL' => "smtp://127.0.0.1:$port",
            // Nothing in this process may reach the database of the machine it runs on.
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'APP_CONFIG_CACHE' => storage_path('framework/testing/no-config-cache.php'),
        ])->run([
            PHP_BINARY,
            '-r',
            'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
            .' try { app("mail.manager")->mailer("smtp")->raw("x", fn ($m) => $m->to("a@example.com")->subject("s")); echo "sent"; }'
            .' catch (Throwable $e) { echo get_class($e).": ".$e->getMessage(); }',
        ]);
        $took = microtime(true) - $started;

        $this->assertTrue($result->successful(), $result->errorOutput().$result->output());
        $this->assertStringStartsWith(TransportException::class.': ', trim($result->output()), 'The send should have failed with a transport error.');
        $this->assertGreaterThan(1.8, $took, 'It gave up before the limit.');
        $this->assertLessThan(15, $took, 'It waited much longer than the limit of 2 seconds.');
    }

    #[Test]
    public function a_failover_mailer_goes_on_to_the_next_one_when_the_smtp_server_is_silent(): void
    {
        $port = $this->silentServer();

        config([
            'mail.mailers.smtp' => array_merge(config('mail.mailers.smtp'), ['host' => '127.0.0.1', 'port' => $port, 'url' => null, 'timeout' => 2.0]),
            'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'array'], 'retry_after' => 60],
        ]);
        Mail::purge('failover');
        Mail::purge('smtp');

        $this->assertSame(2.0, $this->smtpStream()->getTimeout());

        $sent = [];
        Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent) {
            $sent[] = $event->message->getSubject();
        });

        $started = microtime(true);
        Mail::mailer('failover')->raw('hello', fn ($message) => $message->to('someone@example.com')->subject('Falls through'));
        $took = microtime(true) - $started;

        $this->assertSame(['Falls through'], $sent, 'The email did not go out through the next mailer.');
        $this->assertGreaterThan(1.8, $took, 'The silent SMTP server was not waited for.');
        $this->assertLessThan(15, $took);
    }
}

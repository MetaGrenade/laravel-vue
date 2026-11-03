<?php

namespace Tests\Feature\Mail;

use App\Events\OrderPaid;
use App\Http\Middleware\SetLocale;
use App\Models\EmailDigestItem;
use App\Models\Order;
use App\Models\User;
use App\Notifications\BanLifted;
use App\Notifications\OrderConfirmation;
use App\Support\Localization\Locales;
use App\Support\Members\DirectMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

/**
 * What the site sends a person, an email or a notice in the site, is in that person's language, whatever language the request that
 * caused it was in (docs/i18n.md, "Emails"). The site ships English only; these tests add a French that has just the lines they need.
 */
class MailLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['i18n.supported' => ['en', 'fr'], 'i18n.default' => 'en', 'app.locale' => 'en']);

        Lang::addLines([
            'mail.greeting' => 'Bonjour :name !',
            'mail.ban_lifted.subject_lifted' => 'Votre bannissement a été levé',
            'mail.ban_lifted.lifted' => 'Un modérateur a levé le bannissement de votre compte.',
            'mail.direct_message.title' => ':name vous a écrit.',
            'mail.order.confirmation.subject' => 'Votre commande :number',
        ], 'fr');
    }

    /**
     * @return Collection<int, SentMessage>
     */
    private function sentMail(): Collection
    {
        return collect(app('mailer')->getSymfonyTransport()->messages());
    }

    #[Test]
    public function a_members_language_is_that_of_their_locale_setting_if_the_site_has_it_and_the_sites_own_if_not(): void
    {
        $this->assertSame('fr', User::factory()->make(['locale' => 'fr'])->preferredLocale());
        $this->assertSame('fr', User::factory()->make(['locale' => 'fr_CA'])->preferredLocale());
        $this->assertSame('fr', User::factory()->make(['locale' => 'FR-ca'])->preferredLocale());
        $this->assertSame('en', User::factory()->make(['locale' => 'de'])->preferredLocale());
        $this->assertSame('en', User::factory()->make(['locale' => null])->preferredLocale());
        $this->assertSame('en', User::factory()->make(['locale' => 'not a locale'])->preferredLocale());

        config(['i18n.default' => 'fr']);

        $this->assertSame('fr', User::factory()->make(['locale' => 'de'])->preferredLocale());
    }

    #[Test]
    public function the_sites_own_language_is_not_the_language_of_the_request_in_hand(): void
    {
        // Laravel's setLocale() writes the request's language into `app.locale`, so that cannot be where the site's own is kept.
        app()->setLocale('fr');

        $this->assertSame('fr', config('app.locale'));
        $this->assertSame('en', Locales::default());
        $this->assertSame('en', User::factory()->make(['locale' => null])->preferredLocale());
        $this->assertSame('en', User::factory()->make(['locale' => 'de'])->preferredLocale());
    }

    #[Test]
    public function a_member_whose_language_the_site_does_not_have_is_not_written_to_in_the_language_of_the_request_that_caused_the_email(): void
    {
        // The column cannot be empty; what it can hold is a language the site has since stopped (or never started) offering.
        $member = User::factory()->create(['locale' => 'de']);

        // A moderator who reads French, going through the middleware that chooses the language of the request.
        $request = Request::create('/', 'GET', [], ['locale' => 'fr']);

        (new SetLocale)->handle($request, function () use ($member) {
            $this->assertSame('fr', app()->getLocale());

            $member->notify(new BanLifted(false));

            return response('');
        });

        $this->assertSame('Your ban has been lifted', $this->sentMail()->last()->getOriginalMessage()->getSubject());
    }

    #[Test]
    public function an_email_is_written_in_the_language_of_the_person_it_is_for_whatever_the_language_of_the_request(): void
    {
        $member = User::factory()->create(['locale' => 'fr_CA', 'nickname' => 'Avery']);

        // The moderator who lifts a ban is working in English.
        $this->assertSame('en', app()->getLocale());

        $member->notify(new BanLifted(false));

        $email = $this->sentMail()->last()->getOriginalMessage();

        $this->assertSame('Votre bannissement a été levé', $email->getSubject());
        $this->assertStringContainsString('Bonjour Avery !', (string) $email->getTextBody());
        $this->assertStringContainsString('Un modérateur a levé le bannissement de votre compte.', (string) $email->getTextBody());
        // What the French has no line for is said in English, and the language of the request is put back.
        $this->assertStringContainsString('Please keep to the rules', (string) $email->getTextBody());
        $this->assertSame('en', app()->getLocale());
    }

    #[Test]
    public function a_member_with_the_sites_language_is_written_to_in_it_even_when_another_member_caused_the_email_in_french(): void
    {
        $member = User::factory()->create(['locale' => 'en']);

        app()->setLocale('fr');

        $member->notify(new BanLifted(false));

        $this->assertSame('Your ban has been lifted', $this->sentMail()->last()->getOriginalMessage()->getSubject());
        $this->assertSame('fr', app()->getLocale());
    }

    #[Test]
    public function a_queued_notification_is_written_when_it_is_sent_in_the_language_the_member_has_then(): void
    {
        Queue::fake();

        $member = User::factory()->create(['locale' => 'en']);
        $member->notify(new BanLifted(false));

        // The member changes their language while the email waits for a worker.
        $member->forceFill(['locale' => 'fr'])->save();

        $job = Queue::pushed(SendQueuedNotifications::class)->first();
        $this->assertNotNull($job, 'the email was not queued');

        $job->handle(app(ChannelManager::class));

        $this->assertSame('Votre bannissement a été levé', $this->sentMail()->last()->getOriginalMessage()->getSubject());
        $this->assertSame('en', app()->getLocale());
    }

    #[Test]
    public function the_notice_in_the_site_is_stored_in_the_language_of_the_person_it_is_for(): void
    {
        $author = User::factory()->create(['nickname' => 'Robin', 'locale' => 'en']);
        $recipient = User::factory()->create(['locale' => 'fr']);

        app(DirectMessages::class)->send($author, $recipient, 'Hello');

        $notice = DatabaseNotification::query()->where('notifiable_id', $recipient->id)->latest()->first();

        $this->assertNotNull($notice, 'no notice was stored');
        $this->assertSame('Robin vous a écrit.', $notice->data['title']);
        $this->assertSame('en', app()->getLocale());
    }

    #[Test]
    public function the_line_kept_for_a_daily_digest_is_in_the_language_of_the_person_it_is_for(): void
    {
        $author = User::factory()->create(['nickname' => 'Robin', 'locale' => 'en']);
        $recipient = User::factory()->create(['locale' => 'fr']);
        $recipient->notificationSettings()->create([
            'category' => 'messages',
            'channel_mail' => true,
            'channel_push' => true,
            'channel_database' => true,
            'daily_digest' => true,
        ]);
        $recipient->unsetRelation('notificationSettings');

        app(DirectMessages::class)->send($author, $recipient, 'Hello');

        $this->assertSame(['Robin vous a écrit.'], EmailDigestItem::query()->where('user_id', $recipient->id)->pluck('title')->all());
    }

    #[Test]
    public function the_emails_about_an_order_are_in_the_buyers_language_or_else_the_sites(): void
    {
        Notification::fake();

        $member = User::factory()->create(['locale' => 'fr']);
        $mine = Order::factory()->forUser($member)->paid()->create();
        $guests = Order::factory()->paid()->create(['customer_email' => 'guest@example.test']);

        // The payment is confirmed in a request in French (a webhook has none, an admin marking an order paid has their own).
        app()->setLocale('fr');

        event(new OrderPaid($mine));
        event(new OrderPaid($guests));

        Notification::assertSentOnDemand(OrderConfirmation::class, fn (OrderConfirmation $notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === $member->email && $notification->locale === 'fr');
        Notification::assertSentOnDemand(OrderConfirmation::class, fn (OrderConfirmation $notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'guest@example.test' && $notification->locale === 'en');
    }

    #[Test]
    public function an_order_email_is_in_the_language_it_was_sent_with(): void
    {
        $member = User::factory()->create(['locale' => 'fr']);
        $order = Order::factory()->forUser($member)->paid()->create(['number' => 'ORD-7']);

        Notification::route('mail', $order->customer_email)->notify((new OrderConfirmation($order))->locale($order->notificationLocale()));

        $this->assertSame('Votre commande ORD-7', $this->sentMail()->last()->getOriginalMessage()->getSubject());
    }

    #[Test]
    public function every_key_the_notifications_use_is_in_the_language_file_and_is_given_the_values_it_asks_for(): void
    {
        $problems = [];
        $checked = 0;

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            foreach ($this->calls($file->getContents()) as [$function, $key, $values]) {
                $checked++;

                if (! Lang::has($key, 'en', false)) {
                    $problems[] = "{$file->getRelativePathname()}: {$key} is not in lang/en/mail.php";

                    continue;
                }

                $asked = [];

                foreach (explode('|', (string) Lang::get($key, [], 'en', false)) as $form) {
                    preg_match_all('/(?<![A-Za-z0-9]):([a-z][a-z_]*)/', $form, $found);
                    $asked = array_merge($asked, $found[1]);
                }

                $asked = array_values(array_diff(array_unique($asked), $function === 'trans_choice' ? ['count'] : []));
                sort($asked);
                sort($values);

                if ($asked !== $values) {
                    $problems[] = "{$file->getRelativePathname()}: {$key} asks for [".implode(', ', $asked).'] and is given ['.implode(', ', $values).']';
                }
            }
        }

        $this->assertGreaterThan(100, $checked, 'the notifications were not read');
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * The calls in some PHP that name a `mail.` key and are written out: `__('mail.x', [...])` and `trans_choice('mail.x', $n, [...])`,
     * with the names of the values each is given.
     *
     * @return list<array{0: string, 1: string, 2: list<string>}>
     */
    private function calls(string $php): array
    {
        $calls = [];

        preg_match_all("/\\b(__|trans_choice)\\(\\s*'(mail\\.[a-z0-9_.]+)'/", $php, $starts, PREG_OFFSET_CAPTURE);

        foreach ($starts[0] as $index => [$text, $offset]) {
            $function = $starts[1][$index][0];
            $key = $starts[2][$index][0];
            $open = strpos($php, '(', $offset);
            $arguments = $this->arguments($php, $open);
            $given = $arguments[$function === 'trans_choice' ? 2 : 1] ?? null;
            $values = [];

            if ($given !== null && preg_match('/^\s*\[(.*)\]\s*$/s', $given, $array) === 1) {
                $values = $this->arrayKeys($array[1]);
            }

            $calls[] = [$function, $key, $values];
        }

        return $calls;
    }

    /**
     * The arguments of the call whose `(` is at `$open`, as the text of each.
     *
     * @return list<string>
     */
    private function arguments(string $php, int $open): array
    {
        $arguments = [];
        $depth = 0;
        $quote = null;
        $start = $open + 1;

        for ($i = $open; $i < strlen($php); $i++) {
            $char = $php[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;

                if ($depth === 0) {
                    $arguments[] = substr($php, $start, $i - $start);

                    return $arguments;
                }
            } elseif ($char === ',' && $depth === 1) {
                $arguments[] = substr($php, $start, $i - $start);
                $start = $i + 1;
            }
        }

        return $arguments;
    }

    /**
     * The keys of the entries of an array written out, at its own level: `'a' => f(['b' => 1]), 'c' => 2` has `a` and `c`.
     *
     * @return list<string>
     */
    private function arrayKeys(string $inside): array
    {
        $keys = [];

        foreach ($this->arguments('('.$inside.')', 0) as $entry) {
            if (preg_match("/^\\s*'([a-z_]+)'\\s*=>/", $entry, $key) === 1) {
                $keys[] = $key[1];
            }
        }

        return $keys;
    }
}

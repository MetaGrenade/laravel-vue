<?php

namespace App\Notifications;

use App\Support\Localization\DateFormatter;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells somebody that an API key was made on their account, which is the moment to notice one they did not make. It never holds the
 * key itself, and it is sent by mail whatever the person's notification settings say, as an account security notice is.
 */
class ApiKeyCreated extends Notification implements ShouldQueue
{
    use Queueable;

    /** Made by the person, in Settings → API keys. */
    public const SETTINGS = 'settings';

    /** Made by signing in over the API, with an email address and password. */
    public const SIGN_IN = 'sign-in';

    /** Made to replace another key, which is being rotated. */
    public const ROTATION = 'rotation';

    /** Made by a member of staff, in Admin → Tokens. */
    public const STAFF = 'staff';

    /**
     * @param  list<string>  $scopes  What the key can do, as the person reads it (not the scope names).
     * @param  string|null  $replaced  The name of the key it replaces (rotation).
     * @param  CarbonInterface|null  $replacedUntil  When the key it replaces stops working; null if it stopped at once (rotation).
     */
    public function __construct(
        public readonly string $name,
        public readonly string $how,
        public readonly array $scopes,
        public readonly bool $fullAccess,
        public readonly ?CarbonInterface $expiresAt,
        public readonly ?string $ip,
        public readonly ?string $replaced = null,
        public readonly ?CarbonInterface $replacedUntil = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function viaQueues(): array
    {
        return ['mail' => 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $dates = DateFormatter::for($notifiable);

        $message = (new MailMessage)
            ->subject(match ($this->how) {
                self::ROTATION => 'An API key on your account was rotated',
                self::STAFF => 'Staff added an API key to your account',
                default => 'A new API key was made on your account',
            })
            ->greeting('Hi '.($notifiable->nickname ?? $notifiable->name ?? 'there').'!')
            ->line(match ($this->how) {
                self::ROTATION => 'Your API key '.self::literal((string) $this->replaced).' was rotated: a new key called '.self::literal($this->name).' was made to replace it.',
                self::STAFF => 'A member of staff made an API key called '.self::literal($this->name).' for your account.',
                self::SIGN_IN => 'An API key called '.self::literal($this->name).' was made by signing in over the API with your email address and password.',
                default => 'An API key called '.self::literal($this->name).' was made in your account settings.',
            });

        if ($this->how === self::ROTATION) {
            $message->line($this->replacedUntil !== null
                ? 'The old key keeps working until '.$dates->dayDateTime($this->replacedUntil).', so whatever uses it has time to be given the new one.'
                : 'The old key stopped working at once.');
        }

        if ($this->ip !== null) {
            $message->line('It was made from the address '.self::literal($this->ip).'.');
        }

        $message->line($this->fullAccess
            ? 'It has full access to your account.'
            : ($this->scopes === [] ? 'It cannot do anything.' : 'It can: '.implode(', ', $this->scopes).'.'));

        $message->line($this->expiresAt !== null
            ? 'It expires on '.$dates->dayDateTime($this->expiresAt).'.'
            : 'It does not expire.');

        return $message
            ->line('If this was you, there is nothing more to do. If it was not, revoke the key now and change your password.')
            ->action('Review your API keys', route('settings.api-keys.index'));
    }

    /**
     * Text somebody else typed, as it can sit in a sentence of the email: inside a code span, so that nothing in it is read as a link,
     * emphasis or a tag (a key's name is chosen by whoever makes it, and staff can make one on somebody else's account).
     */
    public static function literal(string $text): string
    {
        return '`'.str_replace('`', "'", trim((string) preg_replace('/\s+/', ' ', $text))).'`';
    }
}

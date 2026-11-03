<?php

namespace App\Notifications;

use App\Support\Localization\DateFormatter;
use App\Support\Mail\MailText;
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
                self::ROTATION => __('mail.api_key_created.subject_rotation'),
                self::STAFF => __('mail.api_key_created.subject_staff'),
                default => __('mail.api_key_created.subject_created'),
            })
            ->greeting(MailText::greeting($notifiable))
            ->line(match ($this->how) {
                self::ROTATION => __('mail.api_key_created.rotation', ['old' => self::literal((string) $this->replaced), 'name' => self::literal($this->name)]),
                self::STAFF => __('mail.api_key_created.staff', ['name' => self::literal($this->name)]),
                self::SIGN_IN => __('mail.api_key_created.sign_in', ['name' => self::literal($this->name)]),
                default => __('mail.api_key_created.settings', ['name' => self::literal($this->name)]),
            });

        if ($this->how === self::ROTATION) {
            $message->line($this->replacedUntil !== null
                ? __('mail.api_key_created.old_until', ['date' => $dates->dayDateTime($this->replacedUntil)])
                : __('mail.api_key_created.old_stopped'));
        }

        if ($this->ip !== null) {
            $message->line(__('mail.api_key_created.address', ['address' => self::literal($this->ip)]));
        }

        $message->line($this->fullAccess
            ? __('mail.api_key_created.full_access')
            : ($this->scopes === [] ? __('mail.api_key_created.no_access') : __('mail.api_key_created.scopes', ['scopes' => implode(', ', $this->scopes)])));

        $message->line($this->expiresAt !== null
            ? __('mail.api_key_created.expires', ['date' => $dates->dayDateTime($this->expiresAt)])
            : __('mail.api_key_created.does_not_expire'));

        return $message
            ->line(__('mail.api_key_created.if_not_you'))
            ->action(__('mail.api_key_created.action'), route('settings.api-keys.index'));
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

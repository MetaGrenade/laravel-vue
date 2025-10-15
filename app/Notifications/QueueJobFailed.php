<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Throwable;

class QueueJobFailed extends Notification
{
    public function __construct(
        protected string $job,
        protected ?string $reference,
        protected Throwable $exception,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = 'Queue job failed: ' . class_basename($this->job);
        $lines = [
            'A queued job has failed after exhausting its retries.',
            'Job: ' . $this->job,
        ];

        if ($this->reference) {
            $lines[] = 'Context: ' . $this->reference;
        }

        $lines[] = 'Error: ' . Str::limit($this->exception->getMessage(), 200);
        $lines[] = 'Please review the application logs for full details and take appropriate action.';

        $mail = (new MailMessage())->subject($subject);

        foreach ($lines as $line) {
            $mail->line($line);
        }

        return $mail;
    }
}

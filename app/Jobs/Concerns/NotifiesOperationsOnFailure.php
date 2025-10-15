<?php

namespace App\Jobs\Concerns;

use App\Notifications\QueueJobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

trait NotifiesOperationsOnFailure
{
    protected function notifyOfFailure(Throwable $exception, array $context = []): void
    {
        Log::error($context['message'] ?? 'Queue job failed.', array_merge($context, [
            'exception' => $exception,
        ]));

        if (! config('queue.alerts.enabled')) {
            return;
        }

        $recipient = config('queue.alerts.mail');

        if (! $recipient) {
            return;
        }

        Notification::route('mail', $recipient)->notify(new QueueJobFailed(
            $context['job'] ?? static::class,
            $context['reference'] ?? null,
            $exception,
        ));
    }
}

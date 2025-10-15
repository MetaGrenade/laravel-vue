<?php

namespace Tests\Unit\Jobs;

use App\Jobs\HandleSupportTicketMessagePosted;
use App\Jobs\RecordTokenCreatedActivity;
use App\Notifications\QueueJobFailed;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class QueueFailureNotificationTest extends TestCase
{
    public function test_support_ticket_job_failure_triggers_alert(): void
    {
        Notification::fake();

        config()->set('queue.alerts.enabled', true);
        config()->set('queue.alerts.mail', 'ops@example.com');

        $job = new HandleSupportTicketMessagePosted(10, 25, 5);
        $job->failed(new \RuntimeException('Testing failure'));

        Notification::assertSentOnDemand(QueueJobFailed::class, function (QueueJobFailed $notification, array $channels, AnonymousNotifiable $notifiable) {
            return in_array('mail', $channels, true)
                && ($notifiable->routes['mail'] ?? []) === ['ops@example.com'];
        });
    }

    public function test_token_logging_job_failure_triggers_alert(): void
    {
        Notification::fake();

        config()->set('queue.alerts.enabled', true);
        config()->set('queue.alerts.mail', 'ops@example.com');

        $job = new RecordTokenCreatedActivity(55, 7);
        $job->failed(new \RuntimeException('Token logging failure'));

        Notification::assertSentOnDemand(QueueJobFailed::class, function (QueueJobFailed $notification, array $channels, AnonymousNotifiable $notifiable) {
            return in_array('mail', $channels, true)
                && ($notifiable->routes['mail'] ?? []) === ['ops@example.com'];
        });
    }
}

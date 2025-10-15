<?php

namespace App\Support;

use App\Models\SupportTeam;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Notifications\Notification as BaseNotification;

class SupportTicketNotificationDispatcher
{
    /**
     * @param  callable(string): BaseNotification  $notificationFactory
     * @param  callable(string, User): array<int, string>|null  $channelResolver
     */
    public function dispatch(
        SupportTicket $ticket,
        callable $notificationFactory,
        ?callable $channelResolver = null,
    ): void {
        $ticket->loadMissing([
            'user',
            'assignee.supportTeams.members',
            'team.members',
        ]);

        $recipientCandidates = collect();

        if ($ticket->user) {
            $recipientCandidates->push([
                'audience' => 'owner',
                'user' => $ticket->user,
            ]);
        }

        if ($ticket->assignee) {
            $recipientCandidates->push([
                'audience' => 'agent',
                'user' => $ticket->assignee,
            ]);

            $teamMembers = $ticket->assignee->supportTeams
                ->flatMap(fn (SupportTeam $team) => $team->members)
                ->filter()
                ->map(fn (User $member) => [
                    'audience' => 'team',
                    'user' => $member,
                ]);

            $recipientCandidates = $recipientCandidates->merge($teamMembers);
        }

        if ($ticket->team) {
            $teamMembers = $ticket->team->members
                ->filter()
                ->map(fn (User $member) => [
                    'audience' => 'team',
                    'user' => $member,
                ]);

            $recipientCandidates = $recipientCandidates->merge($teamMembers);
        }

        $audiencePriority = [
            'owner' => 0,
            'agent' => 1,
            'team' => 2,
        ];

        $recipients = $recipientCandidates
            ->filter(fn (array $candidate) => $candidate['user'] instanceof User)
            ->reduce(function (array $carry, array $candidate) use ($audiencePriority, $ticket) {
                /** @var User $user */
                $user = $candidate['user'];
                $audience = $candidate['audience'];
                $id = (int) $user->id;

                if ($id === 0) {
                    return $carry;
                }

                if (! isset($carry[$id]) || $audiencePriority[$audience] < $audiencePriority[$carry[$id]['audience']]) {
                    $carry[$id] = [
                        'user' => $user,
                        'audience' => (int) $user->id === (int) $ticket->user_id ? 'owner' : $audience,
                    ];
                }

                return $carry;
            }, []);

        collect($recipients)
            ->values()
            ->each(function (array $recipientData) use ($ticket, $notificationFactory, $channelResolver): void {
                /** @var User $recipient */
                $recipient = $recipientData['user'];
                $audience = $recipientData['audience'];

                if ($audience === 'team' && ! $recipient->wantsSupportTeamNotifications()) {
                    return;
                }

                $preferredChannels = $recipient->preferredNotificationChannelsFor('support', ['database', 'mail', 'push']);
                $channels = $preferredChannels;

                if ($channelResolver) {
                    $resolvedChannels = $channelResolver($audience, $recipient);

                    if ($resolvedChannels !== null) {
                        $channels = array_map(
                            static fn (string $channel) => $channel === 'push' ? 'broadcast' : $channel,
                            $resolvedChannels,
                        );
                        $channels = array_values(array_unique($channels));
                        $channels = array_values(array_intersect($channels, $preferredChannels));
                    }
                }

                if ($channels === []) {
                    return;
                }

                $synchronousChannels = array_values(array_intersect($channels, ['database']));
                $queuedChannels = array_values(array_diff($channels, $synchronousChannels));

                if ($synchronousChannels !== []) {
                    $recipient->notifyNow(
                        $this->notificationWithChannels(
                            $notificationFactory($audience),
                            $synchronousChannels,
                        ),
                        $synchronousChannels,
                    );
                }

                if ($queuedChannels !== []) {
                    $recipient->notify(
                        $this->notificationWithChannels(
                            $notificationFactory($audience),
                            $queuedChannels,
                        ),
                    );
                }
            });
    }

    /**
     * @param  array<int, string>  $channels
     */
    protected function notificationWithChannels(BaseNotification $notification, array $channels): BaseNotification
    {
        if (method_exists($notification, 'withChannels')) {
            return $notification->withChannels($channels);
        }

        if (property_exists($notification, 'channels')) {
            $notification->channels = $channels;
        }

        return $notification;
    }

}

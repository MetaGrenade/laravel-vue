<?php

namespace Database\Seeders;

use App\Models\Blog;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AcpDashboardDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            [$admin, $users] = $this->seedUsers();

            $this->seedBlogs($admin, $users);
            $this->seedSupportTickets($admin, $users);
        });

        $this->command?->info('ACP dashboard demo data seeded. You can now open the dashboard to verify live metrics.');
    }

    /**
     * Seed a predictable set of users covering the last year of activity.
     *
     * @return array{0: User, 1: Collection<int, User>}
     */
    protected function seedUsers(): array
    {
        $now = now();
        $password = Hash::make('password');

        $adminCreatedAt = $now->copy()->subMonths(11)->startOfMonth()->addDays(2);

        $admin = User::updateOrCreate(
            ['email' => 'acp-dashboard-admin@example.com'],
            [
                'nickname' => 'Dashboard Admin',
                'password' => $password,
                'email_verified_at' => $adminCreatedAt,
                'last_activity_at' => $now->copy()->subDay(),
            ]
        );
        $admin->forceFill([
            'created_at' => $adminCreatedAt,
            'updated_at' => $now,
        ])->saveQuietly();

        $users = collect();

        foreach (range(0, 11) as $offset) {
            $monthStart = $now->copy()->startOfMonth()->subMonths($offset);

            foreach (range(1, 3) as $sequence) {
                $createdAt = $monthStart->copy()->addDays($sequence * 5);
                $email = sprintf('acp-dashboard-user-%02d-%d@example.com', $offset + 1, $sequence);

                $user = User::updateOrCreate(
                    ['email' => $email],
                    [
                        'nickname' => sprintf('Demo User %02d-%d', $offset + 1, $sequence),
                        'password' => $password,
                        'email_verified_at' => $createdAt,
                        'last_activity_at' => $createdAt->copy()->addDays(10),
                    ]
                );
                $user->forceFill([
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ])->saveQuietly();

                $users->push($user);
            }
        }

        $recentUserDefinitions = [
            ['suffix' => 'alpha', 'daysAgo' => 1],
            ['suffix' => 'beta', 'daysAgo' => 3],
            ['suffix' => 'gamma', 'daysAgo' => 5],
        ];

        foreach ($recentUserDefinitions as $definition) {
            $createdAt = $now->copy()->subDays($definition['daysAgo']);
            $email = sprintf('acp-dashboard-user-this-week-%s@example.com', $definition['suffix']);

            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'nickname' => Str::title(str_replace('-', ' ', $definition['suffix'])) . ' Tester',
                    'password' => $password,
                    'email_verified_at' => $createdAt,
                    'last_activity_at' => $createdAt->copy()->addHours(6),
                ]
            );
            $user->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->saveQuietly();

            $users->push($user);
        }

        return [$admin, $users->values()];
    }

    /**
     * Seed a mix of published, draft, and archived blogs for dashboard metrics.
     */
    protected function seedBlogs(User $admin, Collection $users): void
    {
        $now = now();
        $authors = $users->shuffle()->take(4)->prepend($admin)->values();

        $blogDefinitions = [
            [
                'slug' => 'demo-welcome-to-the-dashboard',
                'title' => 'Welcome to the Live ACP Dashboard',
                'status' => 'published',
                'published_at' => $now->copy()->subDays(9),
                'created_at' => $now->copy()->subDays(12),
                'author' => $admin,
            ],
            [
                'slug' => 'demo-monthly-product-recap',
                'title' => 'Monthly Product Recap',
                'status' => 'published',
                'published_at' => $now->copy()->subDays(37),
                'created_at' => $now->copy()->subDays(40),
            ],
            [
                'slug' => 'demo-community-highlights',
                'title' => 'Community Highlights to Share',
                'status' => 'draft',
                'published_at' => null,
                'created_at' => $now->copy()->subDays(18),
            ],
            [
                'slug' => 'demo-support-efficiency-wins',
                'title' => 'Support Efficiency Wins for Q2',
                'status' => 'published',
                'published_at' => $now->copy()->subDays(65),
                'created_at' => $now->copy()->subDays(70),
            ],
            [
                'slug' => 'demo-archived-roadmap-update',
                'title' => 'Archived: Roadmap Update from Last Year',
                'status' => 'archived',
                'published_at' => $now->copy()->subMonths(8),
                'created_at' => $now->copy()->subMonths(9),
            ],
            [
                'slug' => 'demo-welcome-to-the-dashboard-2',
                'title' => 'Welcome to the Live ACP Dashboard II',
                'status' => 'published',
                'published_at' => $now->copy()->subDays(9),
                'created_at' => $now->copy()->subDays(12),
                'author' => $admin,
            ],
            [
                'slug' => 'demo-monthly-product-recap-2',
                'title' => 'Monthly Product Recap II',
                'status' => 'published',
                'published_at' => $now->copy()->subDays(37),
                'created_at' => $now->copy()->subDays(40),
            ],
            [
                'slug' => 'demo-community-highlights-2',
                'title' => 'Community Highlights to Share II',
                'status' => 'draft',
                'published_at' => null,
                'created_at' => $now->copy()->subDays(18),
            ],
            [
                'slug' => 'demo-support-efficiency-wins-2',
                'title' => 'Support Efficiency Wins for Q2 II',
                'status' => 'published',
                'published_at' => $now->copy()->subDays(65),
                'created_at' => $now->copy()->subDays(70),
            ],
        ];

        foreach ($blogDefinitions as $index => $definition) {
            $author = $definition['author'] ?? $authors[$index % $authors->count()];
            $body = $definition['body'] ?? $this->demoBodyCopy($definition['title']);
            $createdAt = $definition['created_at'];
            $updatedAt = $definition['updated_at'] ?? $definition['published_at'] ?? $createdAt;

            $blog = Blog::updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'title' => $definition['title'],
                    'excerpt' => $definition['excerpt'] ?? Str::limit(strip_tags($body), 160),
                    'body' => $body,
                    'user_id' => $author->id,
                    'status' => $definition['status'],
                    'published_at' => $definition['published_at'],
                ]
            );
            $blog->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ])->saveQuietly();
        }
    }

    /**
     * Seed a variety of support tickets to drive the dashboard counts and activity feed.
     */
    protected function seedSupportTickets(User $admin, Collection $users): void
    {
        $now = now();
        $userPool = $users->shuffle()->values();

        $subjects = [
            'Onboarding question about team invites',
            'Billing discrepancy on annual invoice',
            'Analytics dashboard showing blank state',
            'Request for bulk user import assistance',
            'Feature request: Saved dashboard filters',
            'Bug: Notifications not sending',
            'Clarification on permissions model',
            'API key rotation best practices',
            'Mobile layout spacing feedback',
            'Localization strings missing',
            'Unable to upload hero images',
            'Two-factor authentication reset',
        ];

        foreach (range(0, 11) as $offset) {
            $subject = sprintf('Demo Ticket %02d: %s', $offset + 1, $subjects[$offset]);
            $createdAt = $now->copy()->startOfMonth()->subMonths($offset)->addDays(6);
            $status = match (true) {
                $offset === 0 => 'open',
                $offset === 1 => 'pending',
                $offset <= 4 => 'closed',
                $offset % 3 === 0 => 'pending',
                default => 'closed',
            };
            $priority = ['high', 'medium', 'low'][$offset % 3];
            $assigneeId = in_array($status, ['open', 'pending'], true) ? $admin->id : null;
            $updatedAt = match ($status) {
                'closed' => $createdAt->copy()->addDays(4),
                'pending' => $now->copy()->subDays($offset + 2),
                default => $now->copy()->subDay(),
            };

            $requestor = $userPool[$offset % $userPool->count()];

            $ticketModel = SupportTicket::updateOrCreate(
                ['subject' => $subject],
                [
                    'user_id' => $requestor->id,
                    'body' => 'This is seeded sample data to validate the ACP dashboard metrics. No action is required.',
                    'status' => $status,
                    'priority' => $priority,
                    'assigned_to' => $assigneeId,
                ]
            );
            $ticketModel->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ])->saveQuietly();
        }

        $recentTickets = [
            [
                'subject' => 'Demo Ticket: API latency reported by customer',
                'priority' => 'high',
                'status' => 'open',
                'daysAgo' => 2,
            ],
            [
                'subject' => 'Demo Ticket: Styling regression on billing page',
                'priority' => 'medium',
                'status' => 'pending',
                'daysAgo' => 4,
            ],
        ];

        foreach ($recentTickets as $index => $ticket) {
            $createdAt = $now->copy()->subDays($ticket['daysAgo']);
            $requestor = $userPool[($index + 3) % $userPool->count()];

            $ticketModel = SupportTicket::updateOrCreate(
                ['subject' => $ticket['subject']],
                [
                    'user_id' => $requestor->id,
                    'body' => 'Seeded ticket opened this week to validate "new" counts and recent activity ordering.',
                    'status' => $ticket['status'],
                    'priority' => $ticket['priority'],
                    'assigned_to' => $admin->id,
                ]
            );
            $ticketModel->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $ticket['status'] === 'pending' ? $createdAt->copy()->addDay() : $now->copy()->subHours(6),
            ])->saveQuietly();
        }
    }

    /**
     * Provide a reusable block of content for demo blog posts.
     */
    protected function demoBodyCopy(string $title): string
    {
        return <<<HTML
<p><strong>{$title}</strong> is part of the ACP dashboard demo dataset.</p>
<p>Use this seeded content to confirm that the live metrics, trend chart, and activity feed render as expected once real data flows into the system.</p>
<p>After validating the experience you can safely remove or archive this post.</p>
HTML;
    }
}

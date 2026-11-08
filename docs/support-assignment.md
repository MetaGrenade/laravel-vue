# Support desk: routing and SLA

How a new ticket finds the agent or team it goes to, and what the site does on its own with a ticket that nobody has dealt with: raise its priority, or hand it to someone else. All of it is set up in the admin area; nothing needs the command line or a code change.

> **Status:** built and covered by tests. The monitor is a scheduled job, so it only works while the scheduler and a queue worker are running ([Deploying](deployment.md#what-a-live-site-is-made-of)). Who may open each page is in [Support permissions](support-permissions.md).

## Assignment rules

**Admin → Support → Assignment rules** (`/acp/support/assignment-rules`). A rule says "a ticket like this goes to that person or team". A rule has:

- **A category**, or none for any category.
- **A priority** (`low`, `medium` or `high`), or none for any priority.
- **An assignee:** one agent, or a support team (see below).
- **Active**, so a rule can be set aside without deleting it.
- **A position.** The rules are in a list, and the buttons on the page move a rule up or down.

When a ticket is made, from the support page, from the admin area or through the API, the rules are read from the top and **the first one that matches wins**. Put the most specific rules first and a catch-all last. A rule whose agent or team cannot be found any more is skipped. If no rule matches, the ticket is left unassigned.

Assigning to a **team** puts the ticket on the team and clears any individual assignee. Who is in a team is edited at **Admin → Support → Teams** (`/acp/support/teams`): an agent can be added to or removed from a team there without touching the database.

The rules are kept in the cache and flushed whenever one is saved, moved or deleted, so a change takes effect on the next ticket.

## Service levels (SLA)

**Admin → Support → SLA** (`/acp/support/sla`, "Manage support SLA thresholds") holds two things for each priority. The values are the same text PHP reads as a time span: `48 hours`, `2 days`, `90 minutes`.

- **Priority escalations.** After this long, a ticket that is still *open* or *pending* moves up to a higher priority. The shipped settings are `low` to `medium` after 48 hours, and `medium` to `high` after 24 hours. `high` has nowhere to go.
- **Reassign after.** After this long without an update, a ticket that is assigned to a person is handed to somebody else: `low` after 72 hours, `medium` after 36, `high` after 12. The person it has now is left out, and the rules are read again from the top; the first matching rule that is not that person wins. If none does, the ticket stays where it is.

Leave a field empty to switch that step off. What is saved on the page is kept as a setting (`support.sla`) and wins over the defaults in `config/support.php`, which are only what a site starts with: editing the file after the page has been saved changes nothing.

### When the monitor runs

The scheduler queues `MonitorSupportTicketSlas` **every 15 minutes** (`bootstrap/app.php`), and a worker runs it. It looks at the open and pending tickets only. For each, in this order, it reassigns and then escalates. These details are easy to be surprised by:

- **Escalation is counted from the day the ticket was made,** not from its last escalation. With the shipped settings a `low` ticket is `medium` after 48 hours and `high` about 15 minutes after that, because it is already more than 24 hours old. To have `high` come 24 hours after `medium`, set `medium` to `high` to `72 hours`, which is counted from the day the ticket was made.
- **Reassignment is counted from the last update.** Anything that saves the ticket counts: a reply (from the customer or from staff), a change of status or assignee, the monitor's own change of priority and a reassignment. Each starts the clock again.
- **A ticket on a team is not reassigned,** because it has no individual assignee. Only tickets assigned to a person are.
- **A change is only made when there is a change.** If the first matching rule is the person who already has the ticket and is excluded, the monitor goes on to the next rule, and when none is left it does nothing and writes nothing.

## What is recorded

Every automatic change is written to `support_ticket_audits`, and shows in the history on the ticket's page in the admin area: `auto_assigned` (a rule at creation), `sla_reassigned` (the monitor, with the threshold) and `priority_escalated` (the monitor, with the old and new priority and the threshold). A person's own change of assignee or priority is a normal admin action and is in the [audit log](audit-log.md).

## Doing it from a script

Rules are ordinary records (`App\Models\SupportAssignmentRule`) if you need to create them from a seeder or a console:

```php
SupportAssignmentRule::create([
    'support_ticket_category_id' => 1,   // optional
    'priority' => 'high',                // optional
    'assignee_type' => 'team',           // 'user' (the default) or 'team'
    'support_team_id' => 2,              // when it is a team
    'assigned_to' => null,               // the user's id, when it is a user
    'position' => 10,                    // lower is read first
]);
```

Saving it flushes the cached list, as saving it on the page does. Prefer the page: it validates that a team or an agent is given.

## Testing

`tests/Unit/SupportTicketAutoAssignerTest.php` holds the matching (the first matching rule, a reassignment that skips the excluded agent, a team setting the team and a user clearing it, the cached list flushed when an agent or a team is deleted). `tests/Feature/Admin/SupportAssignmentRuleTest.php` holds the page: creating, ordering and giving a rule to a team. `tests/Feature/Support/MonitorSupportTicketSlasTest.php` holds the monitor: escalation after the threshold, reassignment to the next agent, and thresholds taken from the saved setting, with the audit rows.

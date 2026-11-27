# Admin permissions

The rest of the admin area follows named permissions. Blogs, members and the support desk are in their own notes.

> **Status:** built: roles, the forum, trust and safety, badges, tokens, and the menu for search. **Not built:** see [below](#not-built).

## Using it

An administrator has every permission. An editor or a moderator needs the permission itself. The seeder does not give them roles, the forum structure, badges, tokens or trust and safety. A moderator can still open the forum list, the reports and the review queue.

**Roles** (`acl.acp.view`, `create`, `edit`, `delete`) open and change roles and permissions. **Trust and safety** (`trust_safety.acp.view`, `edit`) opens the console and updates an export or an erasure. **Badges** (`reputation.acp.view`, `create`, `edit`, `delete`) open and change badges. **Tokens** (`tokens.acp.view`, `create`, `edit`, `delete`) open the list and a log, make a key, change or revoke one, and delete one.

**Forums.** `forums.acp.view` opens the list, the prefixes and the reports, and can mark a report reviewed. `create`, `edit`, `delete` and `move` change categories, boards and prefixes. `review` is the held queue. `automod` is the rules. Locking or unlocking a thread from a report needs `forums.acp.lock`. Publishing or unpublishing it needs `forums.acp.publish`. Deleting a reply from a report needs `forums.acp.delete`.

**Search** is `search.acp.view`. The menu shows it only with that permission.

The **dashboard** is for any staff member (`admin`, `editor` or `moderator`). The member bulk action is one route that can verify, warn, ban or delete, so it checks each action in the controller. Blog revisions stay on the blog policy, outside this staff group. Commerce, billing, polls, legal pages, system settings and the audit log already named their permissions.

## What only an administrator can do

A permission opens its own part of the admin area. It is not a way to become an administrator, or to take one's place. Whatever permissions somebody else holds, only an administrator can:

- **give an account the `admin` role** (**Admin → Users → Edit**);
- **change an administrator's account**: edit it (which is also where a role is taken away), verify its email address or delete it, one at a time or in the bulk action, and link or unlink a sign-in provider (Google, Discord...);
- **make, change, revoke or delete an administrator's API key** (**Admin → Tokens**). A key acts as its account, and whoever makes it is shown it;
- **change the `admin` role**: rename it, delete it, change its permissions, or give another role its name, in any spelling the database reads as the same;
- **add, change or delete a Discord mapping of the `admin` role** ([discord-companion.md](discord-companion.md#who-can-change-what)).

Anybody else is told which of these it was, and the [audit log](audit-log.md) records the attempt as *denied* (the Discord pages are not in the log).

Warnings, bans, content clean-up and turning two-factor off go further: only an administrator can use them on **any** member of staff ([moderation.md](moderation.md), [two-factor.md](two-factor.md)). Nobody can [view the site as](impersonation.md) one.

## Not built

- **A limit on the other roles and permissions.** Somebody with `users.acp.edit` can give an account any role except `admin`, their own account included, and can edit another editor's or moderator's account. Somebody with `acl.acp.edit` can give any role except `admin` any permission, `tokens.*` and `system.*` among them. Hand those two out only to somebody you would trust with nearly all of the admin area.
- **A change to who an administrator is.** An administrator still has every permission.
- **A permission of its own for the dashboard.** Any staff member can open it.

## Testing

`tests/Feature/Admin/AcpAuthorizationTest.php`: an editor with no extra permission can open the dashboard and cannot open the other areas, view opens only its own area, and deleting a role or a token needs delete. `tests/Feature/Admin/ForumReportsTest.php`: viewing a report can mark it reviewed, and locking a thread or deleting a reply needs those permissions.

`tests/Feature/Admin/AdministratorProtectionTest.php`: somebody holding every permission, without the role, changes nothing about an administrator, and an administrator still can. It also lists every admin write that names an account, an API key or a role, so a new one fails the test until it is put in a list. `tests/Feature/Discord/DiscordAdminChangesTest.php` is the same for the Discord pages.

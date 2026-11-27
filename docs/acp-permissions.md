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

A permission opens its own part of the admin area. It is not a way to more than that: not to a permission its holder does not have, not into another staff account, and not to the `admin` role.

An account is **staff** when it has the `admin`, `editor` or `moderator` role, or any permission of the admin area (a name with `.acp.` in it), through a role or on its own. The billing pages open on their permission alone, which is why the three roles are not the whole test. Every other account is a **member**.

Whatever permissions somebody else holds, only an administrator can:

- **change a staff account**: edit it (which is also where a role is added or taken away), verify its email address or delete it, one at a time or in the bulk action, and link or unlink a sign-in provider (Google, Discord...). A staff member's own account is one, so they change their own details in **Settings**;
- **make, change, revoke or delete a staff account's API key** (**Admin → Tokens**). A key acts as its account, and whoever makes it is shown it. Their own keys are in **Settings → API keys**;
- **give an account the `admin`, `editor` or `moderator` role**;
- **change or delete those three roles**, or give another role one of their names, in any spelling the database reads as the same;
- **rename a role or a permission**, or change its guard. The code asks for a permission by its name;
- **take a permission off a role that somebody has**, delete such a role, or delete a permission that a role or an account has;
- **map a Discord role to `admin`, `editor` or `moderator`**, or change or delete such a mapping ([discord-companion.md](discord-companion.md#who-can-change-what)).

### Giving permissions

Somebody who is not an administrator and may manage roles or members **can give only what they hold themselves**, through a role or on their own:

- **a permission to a role** (**Admin → Access control**), a new role or one that is there;
- **a role to a member**, only if they hold every permission on it. A role the member has already stays: saving the page does not give it again;
- **a Discord role mapping**, and a Discord account linked to a member by hand, where the bot would then give a role ([discord-companion.md](discord-companion.md#who-can-change-what)).

The pages switch off what cannot be chosen. Two things follow:

- **What somebody gives, they may not be able to take back.** A role with a permission of the admin area makes the member staff, and a staff account is an administrator's to change.
- **Taking away is limited so that it is no way round the rest.** A role that nobody has, and a permission that is nobody's, can be changed or deleted. Taking a permission off a role somebody has could turn a staff account into a member's, ready to be changed.

A refusal says which of these it was, and the [audit log](audit-log.md) records the attempt as *denied* (the Discord pages are not in the log).

**No role or permission has the shape of a UUID or a ULID** (`00000000000000000000000001`), whoever makes it. The library that keeps roles takes such a string for a record's number, so a role of that name could stand for another role, `admin` among them. The admin pages hand it the roles themselves and not their names, and the Discord pages refuse to map such a name. Code of your own that gives a role by a name somebody typed has to do the same ([upgrading.md](upgrading.md#a-role-name-shaped-like-an-id)).

Warnings, bans, content clean-up and turning two-factor off draw the same line: only an administrator can use them on a staff account ([moderation.md](moderation.md), [two-factor.md](two-factor.md)). Nobody can [view the site as](impersonation.md) one.

## Not built

- **A record of who may give what.** The rule is what the person holds at that moment. What somebody gave stays after they lose the permission themselves.
- **A change to who an administrator is.** An administrator still has every permission.
- **A permission of its own for the dashboard.** Any staff member can open it.

## Testing

`tests/Feature/Admin/AcpAuthorizationTest.php`: an editor with no extra permission can open the dashboard and cannot open the other areas, view opens only its own area, and deleting a role or a token needs delete. `tests/Feature/Admin/ForumReportsTest.php`: viewing a report can mark it reviewed, and locking a thread or deleting a reply needs those permissions.

`tests/Feature/Admin/AdministratorProtectionTest.php`: somebody holding every permission, without the role, changes nothing about an administrator, and an administrator still can. It also lists every admin write that names an account, an API key or a role, so a new one fails the test until it is put in a list. `tests/Feature/Admin/StaffAccountProtectionTest.php` does the same for every kind of staff account, and `tests/Feature/Admin/DelegatedGrantsTest.php` has what can be given and taken away, and the names shaped like an id. `tests/Feature/Discord/DiscordAdminChangesTest.php` is for the Discord pages.

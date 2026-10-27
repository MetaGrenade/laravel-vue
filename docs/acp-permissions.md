# Admin permissions

The rest of the admin area follows named permissions. Blogs, members and the support desk are in their own notes.

> **Status:** built: roles, the forum, trust and safety, badges, tokens, and the menu for search. **Not built:** see [below](#not-built).

## Using it

An administrator has every permission. An editor or a moderator needs the permission itself. The seeder does not give them roles, the forum structure, badges, tokens or trust and safety. A moderator can still open the forum list, the reports and the review queue.

**Roles** (`acl.acp.view`, `create`, `edit`, `delete`) open and change roles and permissions. **Trust and safety** (`trust_safety.acp.view`, `edit`) opens the console and updates an export or an erasure. **Badges** (`reputation.acp.view`, `create`, `edit`, `delete`) open and change badges. **Tokens** (`tokens.acp.view`, `create`, `edit`, `delete`) open the list and a log, make a key, change or revoke one, and delete one.

**Forums.** `forums.acp.view` opens the list, the prefixes and the reports, and can mark a report reviewed. `create`, `edit`, `delete` and `move` change categories, boards and prefixes. `review` is the held queue. `automod` is the rules. Locking or unlocking a thread from a report needs `forums.acp.lock`. Publishing or unpublishing it needs `forums.acp.publish`. Deleting a reply from a report needs `forums.acp.delete`.

**Search** is `search.acp.view`. The menu shows it only with that permission.

The **dashboard** is for any staff member (`admin`, `editor` or `moderator`). The member bulk action is one route that can verify, warn, ban or delete, so it checks each action in the controller. Blog revisions stay on the blog policy, outside this staff group. Commerce, billing, polls, legal pages, system settings and the audit log already named their permissions.

## Not built

- **A change to who an administrator is.** An administrator still has every permission.
- **A permission of its own for the dashboard.** Any staff member can open it.

## Testing

`tests/Feature/Admin/AcpAuthorizationTest.php`: an editor with no extra permission can open the dashboard and cannot open the other areas, view opens only its own area, and deleting a role or a token needs delete. `tests/Feature/Admin/ForumReportsTest.php`: viewing a report can mark it reviewed, and locking a thread or deleting a reply needs those permissions.

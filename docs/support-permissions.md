# Support permissions

The support desk in the admin area follows named permissions.

> **Status:** built: the desk, tickets, replies, categories, FAQs, teams, templates, assignment rules and SLA thresholds. **Not built:** see [below](#not-built).

## Using it

The ticket permissions are `support.acp.view`, `create`, `edit`, `delete`, `assign`, `move`, `publish`, `priority`, `reply` and `status`. Teams, templates and assignment rules have their own `view`, `create`, `edit` and `delete`. An administrator has them. Anyone else needs the permission itself. An editor or a moderator is not given these by default.

**View** opens the desk and a ticket. **Create** starts a ticket, a category or an FAQ. **Edit** changes a ticket, a category, an FAQ or the SLA thresholds. **Delete** removes a ticket, a category or an FAQ. **Reply** answers a ticket. **Status** opens, pauses or closes a ticket, including the bulk action and the status chosen when a ticket is saved. **Priority** changes how urgent a ticket is, including on that save. **Assign** hands a ticket to an agent, including on that save. Leaving the status, priority or agent as they are is still an edit. **Publish** publishes an FAQ or returns it to draft, including the published box on the form. **Move** changes an FAQ's order, including the order on the form. A ticket or an unpublished FAQ can be saved without those.

The category lists are opened from the desk while creating or editing, and so is the member search on a ticket, so create or edit may load them.

## Not built

- **The dashboard.** Any staff member can open it. See [acp-permissions.md](acp-permissions.md).
- **A change to who an administrator is.** An administrator still has every permission.

## Testing

`tests/Feature/Admin/SupportAuthorizationTest.php`: an editor with no support permission cannot open or change the desk, a moderator who can view it cannot change a ticket, saving a ticket cannot change its status, priority or agent without those permissions, and saving an FAQ cannot publish or reorder it without those permissions.

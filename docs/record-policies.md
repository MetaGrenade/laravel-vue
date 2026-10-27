# Who may see an order, a ticket, or a product

An order follows its owner. A ticket follows the person who opened it. A product on the shop is shown when it is for sale.

> **Status:** built: `OrderPolicy`, `SupportTicketPolicy` and `ProductPolicy`. **Not built:** see [below](#not-built).

## Using it

**Orders.** `OrderPolicy` asks the order's owner, never the person who happened to place it. A guest's order has no owner and is opened with the signed link on the receipt. The order history uses the same rule. See [ownership.md](ownership.md).

**Tickets.** `SupportTicketPolicy` asks who opened the ticket. That person can open it, reply, close it, reopen it and rate it, on the site and over the API. Closing and reopening on the site use the same requests as the API. Staff use the support permissions in the admin area, not this policy. Downloading a file follows the same rule, or `support.acp.view`. An administrator can still open, close and reopen any ticket, because an administrator is allowed every ability.

**Products.** `ProductPolicy` says a product is on the shop when it is for sale. The shop asks the policy itself, so an archived product stays hidden there, including from an administrator. Creating, changing and deleting a product are `commerce.acp.create`, `edit` and `delete`. The catalogue routes name those permissions as well.

## Not built

- **A team that shares a ticket.** A ticket stays with the person who opened it. See [ownership.md](ownership.md).
- **A change to who an administrator is.** An administrator still has every ability.

## Testing

`tests/Feature/Support/SupportTicketPolicyTest.php`: only the person who opened a ticket can open it or reply, and an administrator can close and reopen one they did not open. `tests/Feature/Commerce/ProductPolicyTest.php`: an archived product is hidden on the shop, and changing a product needs `commerce.acp.edit`. Orders are covered by `tests/Feature/Ownership/OwnerResolutionTest.php`.

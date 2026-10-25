# The "Get started" checklist

A new customer's dashboard opens with a short list of what is worth doing first: confirm the email address, add a picture or a bio, turn on two-factor authentication, choose what is emailed, choose a plan, make an API key, say hello in the forum. **Each step ticks itself** when the account shows it is done, wherever the person did it.

> **Status:** built: the checklist, its steps, hiding it, and the settings. **Not built:** see [below](#not-built).

## What a customer sees

A card at the top of the dashboard: how far along they are (`2 of 6 done` and a bar), and a line for each step with a button that goes to where it is done. A done step is ticked and struck through; the others say why they are worth doing. **Hide this** puts it away for good.

It goes by itself when:

- every step is done;
- the person hides it (it does not come back if they do more later);
- the account is **30 days old** (`ONBOARDING_DAYS`). This is also what keeps it from appearing to every account that existed before it was built: an upgrade does not put a checklist in front of people who have been customers for a year.

Staff viewing the site as a customer ([impersonation.md](impersonation.md)) see it, but the **Hide this** button is not there and the request would be refused: nothing can be changed in that view.

## The steps

| Step | Done when | Where it goes | Listed |
|---|---|---|---|
| `verify_email` | The email address is confirmed. | The verification notice. | Only where confirming is **not** needed to sign in (a person who got to the dashboard where it is has done it). |
| `profile` | They have a picture or a bio. | Settings → Profile. | Always. |
| `two_factor` | Two-factor authentication is **confirmed** (started is not done). | Settings → Security. | Always. |
| `notifications` | They have saved their notification settings. | Settings → Notifications. | Always. |
| `plan` | They have ever had a subscription. | Pricing. | Only while a plan is on sale. |
| `api_key` | They have a key that has not been revoked. | Settings → API keys. | Always. |
| `community` | They have started a forum thread or written a reply. | The forum. | Only while the forum is switched on. |

A step that could only ever be done, or never be done, is not listed, and with none left the checklist is not shown.

Nothing about the steps is stored. They are read from the account each time the dashboard opens, so a step done before the checklist existed is done, and one undone later (the only API key revoked) is undone. The one thing kept is that the person hid it: `users.onboarding_dismissed_at`.

## Settings

`config/onboarding.php`:

| Setting | |
|---|---|
| `ONBOARDING_ENABLED` | `false` takes the checklist away from everybody. Default on. |
| `ONBOARDING_DAYS` | How many days after an account is made it is shown (`0`: as long as something is left). Default 30. |
| `steps` | The steps and their order. Take one out to drop it. |

The words are in `lang/en/ui.php` under `onboarding` (`ui.onboarding.steps.<key>.title`, `description` and `action`), so they can be translated like the rest of the interface ([i18n.md](i18n.md)).

## Adding a step

1. Add its key to `steps` in `config/onboarding.php`.
2. Add a line for it to `Onboarding::step()` (`App\Support\Onboarding\Onboarding`): the route to go to, and how to tell it is done from the account. Return `null` for it when the site does not have the feature, so that it is not listed.
3. Add its `title`, `description` and `action` to `lang/en/ui.php`. A test fails for a step with no words.
4. Add it to `tests/Feature/Onboarding/OnboardingTest.php` (done, not done, and not listed).

Ask the account, not a flag. A flag that something sets when the person does it is a flag that something forgets to set.

## Testing

`tests/Feature/Onboarding/OnboardingTest.php`: what is on a plain site, how each step ticks (and what does not tick it), the steps a site may not have, every way it goes (done, hidden, too old, switched off, no steps), hiding it (signed in only, for that person only, kept), and staff viewing as a customer.

## Not built

- **Skipping one step** without hiding the whole list.
- **Bringing it back** after it was hidden. Clearing `onboarding_dismissed_at` does.
- **A checklist for staff**, or different ones for different kinds of customer.
- **An email** reminding someone who has not finished it.

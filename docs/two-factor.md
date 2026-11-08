# Two-factor authentication

A member can require a code from an authenticator app at sign-in, and can keep recovery codes for when that app is gone.

> **Status:** built: authenticator codes, recovery codes, a prompt to replace a set that is no longer complete, and a limit on guesses. **Not built:** see [below](#not-built).

## Using it

**Settings → Security.** Turning it on shows a secret. Confirming with a 6-digit code from an authenticator app turns it on and makes eight recovery codes. Each recovery code works once. Generating a new set replaces the old one, so the codes written down before that no longer work.

The codes follow the published test times for this kind of authenticator (RFC 6238, SHA-1). A code from the 30 seconds just before or just after is accepted. Anything else is refused, including a code that is not six digits.

Signing in with a recovery code uses one of the set. Until the member generates a new set of eight, a bar at the top of the site asks them to. The same bar shows when the stored set cannot be read. It is not shown to someone who has not turned this on, and it is not shown while a member of staff is viewing the site as that member.

Guessing is limited on the challenge itself: five tries a minute for that sign-in, and ten a minute from the same place. A recovery code is the same challenge, so it shares the limit.

## Turning it off

**Settings → Security** has a button that turns two-factor off: it clears the secret and the recovery codes at once, and the account goes back to a password alone. A browser that was left signed in must not be enough to do that, so the button **asks for the password first**, on the same page the site uses before any sensitive step (`/confirm-password`). Making a new set of recovery codes asks the same way, because the new set replaces the one written down.

After a correct password the site does not ask again from that browser for `AUTH_PASSWORD_TIMEOUT` seconds (three hours unless changed, see [Configuration](configuration.md)). The member lands back on the Security page and presses the button again. A wrong password changes nothing, and guesses at it are limited to five a minute for each member.

What does **not** ask, and why:

- **Cancelling a setup that was never confirmed.** A secret has been shown but no code entered, so the account never asked for a code at sign-in and nothing is taken away. "Cancel" stays one click.
- **Turning it on.** It takes nothing away, and a member who only ever signed in with a provider has no password to give. Turning it on *again* while it is on is refused with a notice: a new secret clears the confirmation that sign-in goes by, which would have switched two-factor off without asking. Turn it off first (with the password), then set it up again.
- **Ending another browser's session.** It only signs that browser out, so whoever does it gains no way into the account, and a member who has lost a phone should be able to do it at once. A password prompt would make it slower exactly when speed matters.

**A member who never chose a password** (they signed up with Google, Discord or Steam, and their password is a random one nobody was shown) cannot pass the prompt with it. The page says what to do: sign out, use **Forgot your password?** on the sign-in page, then come back. That works when the account has a **verified, real email address**. Steam, and a Discord account with no address, get a made-up `…@oauth.local` address that cannot receive mail; those members cannot turn two-factor off or replace their recovery codes from the site at all (see [Not built](#not-built)).

## Browser sessions

The same page lists the browsers that are signed in to the account: the address and kind of browser, when each last did anything, and the one in use now. Ending one deletes its row from the `sessions` table, so that browser has to sign in again. The one in use cannot be ended from here (sign out instead).

The list **only works with `SESSION_DRIVER=database`**, the default, because it reads that table. On Redis sessions ([Running on Redis](redis.md)) the table is no longer written, so the panel shows nothing true.

## Not built

- **A way through the password prompt for members who have no password.** A made-up `…@oauth.local` address cannot receive a reset link, and **Settings → Password** asks for the current password, so such a member can never give one. They can still sign in (provider, then code); they cannot turn two-factor off or replace the codes. Asking the provider again would be the fix.
- **Staff cannot switch two-factor off for a member.** A member who has lost the app and the recovery codes has no way back in from the site; there is no button in the admin area and no command for it.
- **Asking again before every change.** The password is asked before two-factor is turned off and before a new set of recovery codes. Changing the email address in **Settings → Profile** and making an API key do not ask.
- **Passkeys.** They are deferred.
- **A new set made on its own.** The member generates it, and is shown the new codes. Nothing replaces them in the background.
- **A reminder before a code has been used.** A full set of eight is quiet.

## Testing

`tests/Feature/Auth/TwoFactorAuthenticatorTest.php`: the published times produce the published codes, a code is accepted one step either side and refused beyond that, and a short or unreadable set asks for a new one.

`tests/Feature/Auth/RecoveryCodePromptTest.php`: a short set shows the prompt on the dashboard and on the security page, signing in with a recovery code shows it, generating a new set clears it, a full set and an account without this turned on do not, and viewing as the member hides it.

`tests/Feature/Auth/RateLimitingTest.php`: a sixth guess of an authenticator code, and of a recovery code, is refused.

`tests/Feature/Settings/SecurityPasswordConfirmationTest.php`: turning two-factor off and making new recovery codes ask for the password and change nothing until it is given; a confirmation older than the timeout is asked for again; a wrong password unlocks nothing; the visit from the page is sent to the confirm page with a 303; cancelling an unconfirmed setup, turning it on and ending another session ask for nothing; setting it up again on a confirmed secret is refused.

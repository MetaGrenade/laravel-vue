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
- **Ending one other browser's session from the list.** It only signs that browser out, so whoever does it gains no way into the account, and a member who has lost a phone should be able to do it at once. A password prompt would make it slower exactly when speed matters. (**Sign out other browsers** is different: it does ask, because it also stores the password again, and a browser left open should not be able to sign the owner out of everything.)

**A member who never chose a password** (they signed up with Google, Discord or Steam, and their password is a random one nobody was shown) cannot pass the prompt with it. The page says what to do: sign out, use **Forgot your password?** on the sign-in page, then come back. That works when the account has a **verified, real email address**. Steam, and a Discord account with no address, get a made-up `…@oauth.local` address that cannot receive mail; those members cannot turn two-factor off or replace their recovery codes from the site at all (see [Not built](#not-built)).

## Browser sessions

Three things end a browser that is signed in to the account, and none of them depends on where sessions are kept.

- **Changing the password** (**Settings → Password**, or the link in a password reset email). Every other browser is signed out at its next request, and so is a "remember me" cookie from before the change. The browser that changed it stays in and is given a new cookie. This is Laravel's `AuthenticateSession`: each session keeps a fingerprint of the password it was started with and is ended when the account's password no longer matches it. A refused change (a wrong current password) signs nobody out.
- **Sign out other browsers** on **Settings → Security** asks for the password and does the same without changing it: the password is stored again with a new salt, which is what makes the old fingerprints stop matching. A wrong password changes nothing. Guessing is limited to five tries a minute for each member, shared with the password page.
- **Ending one session** from the list below, which needs a driver that can name its sessions.

Nobody is signed out by the upgrade that added this. A session that has not stored a fingerprint yet stores one at its next request.

### The list

On `SESSION_DRIVER=database`, the default, the page also lists the browsers that are signed in: the address and kind of browser, when each last did anything, and the one in use now. Ending one deletes its row from the `sessions` table, so that browser has to sign in again. The one in use cannot be ended from here (sign out instead). **Sign out other browsers** clears the other rows from the list at once.

With any other driver (Redis, files, cookie, array) the site cannot name its sessions, so the page shows **no list**, says so, and offers only the button ([Running on Redis](redis.md)). Before this, the panel on such a site showed whatever rows were left in the `sessions` table from before the switch, and ending one of them ended nothing that was open.

### Limits

- **A member with no password of their own** (they signed up with Google, Discord or Steam) cannot give one, so cannot use the button or **Settings → Password**. They use **Forgot your password?** on the sign-in page, which sets one and signs every browser out; that needs a verified, real email address, which a made-up `…@oauth.local` address is not.
- **API keys keep working** when the password changes. They are revoked on **Settings → API keys**, one by one.
- **Staff viewing the site as a customer** ([impersonation](impersonation.md)) are checked against their own password, not the customer's: the check runs before the customer is swapped in.
- **A password stored again for another reason** (for instance after the hashing cost was raised, when Laravel rewrites the hash at that member's next sign-in) also ends the other browsers of that member, once.

## Changing the email address

The address is where **Forgot your password?** sends its link. Whoever can change it from a browser that was left open, or whose cookie was taken, can ask for a reset at the new address and own the account, while the person it belonged to is left with an address that reaches nothing. So **Settings → Profile** asks for the current password when the address typed is another one (spaces and case do not make it another). A field appears under the address as soon as it differs; a save that leaves the address as it is asks for nothing.

- **A wrong password changes nothing.** Guesses are limited to five a minute for each member, **counted together** with the other places that check the current password: this form, **Settings → Password**, **Sign out other browsers** and deleting the account. A save of the profile that leaves the address alone is not a guess and is not counted.
- **The old address is told.** After the change an email goes to the address the account **had**, in the member's language: it says when the address was changed, from which network address, and names the new one only in part (`s***@example.org`). It also says that from then on the emails of the site, a password reset included, go to the new address. It is sent whatever the notification settings, as the API key notice is, and a mail that cannot be queued is reported and does not undo the change.
- **There is no undo link in it.** The password was given, so whoever did it has the account. The email says what to do: sign in if you still can, change the password, set the address back, and contact the people who run the site.
- **The new address is unverified** until its link is used, as before, and the verification email goes to it.
- **A member whose address the site made up** (`…@oauth.local`, see [Social login](social-login.md)) is **not asked** and nothing is sent: they have no password they were shown, and this form is how they get an address that can receive mail at all.
- **A member who signed up with a provider, has a real address and never chose a password** cannot pass the prompt. The page says so under the field: sign out, use **Forgot your password?** to set one, come back.

## Not built

- **A way through the password prompt for members who have no password.** A made-up `…@oauth.local` address cannot receive a reset link, and **Settings → Password** asks for the current password, so such a member can never give one. They can still sign in (provider, then code); they cannot turn two-factor off or replace the codes. Asking the provider again would be the fix. (They can change their address, which is how they get a real one.)
- **Staff cannot switch two-factor off for a member.** A member who has lost the app and the recovery codes has no way back in from the site; there is no button in the admin area and no command for it.
- **Asking again before every change.** The password is asked before two-factor is turned off, before a new set of recovery codes and before the email address is changed ([above](#changing-the-email-address)). Making an API key does not ask.
- **A way back for the owner after an address was changed by somebody else.** The notice goes to the old address, and that is all: it has no link that puts the address back or ends the sessions, because whoever changed it knew the password.
- **Passkeys.** They are deferred.
- **A new set made on its own.** The member generates it, and is shown the new codes. Nothing replaces them in the background.
- **A reminder before a code has been used.** A full set of eight is quiet.

## Testing

`tests/Feature/Auth/TwoFactorAuthenticatorTest.php`: the published times produce the published codes, a code is accepted one step either side and refused beyond that, and a short or unreadable set asks for a new one.

`tests/Feature/Auth/RecoveryCodePromptTest.php`: a short set shows the prompt on the dashboard and on the security page, signing in with a recovery code shows it, generating a new set clears it, a full set and an account without this turned on do not, and viewing as the member hides it.

`tests/Feature/Auth/RateLimitingTest.php`: a sixth guess of an authenticator code, and of a recovery code, is refused.

`tests/Feature/Settings/SecurityPasswordConfirmationTest.php`: turning two-factor off and making new recovery codes ask for the password and change nothing until it is given; a confirmation older than the timeout is asked for again; a wrong password unlocks nothing; the visit from the page is sent to the confirm page with a 303; cancelling an unconfirmed setup, turning it on and ending another session ask for nothing; setting it up again on a confirmed secret is refused.

`tests/Feature/Settings/SignOutOtherBrowsersTest.php`: a session started with the old password is signed out (a page visit goes to the sign-in page, not to an error); a session with no fingerprint yet is kept and stores one; changing the password, or resetting it by email, signs the other browsers out and keeps the one that changed it, which gets a "remember me" cookie that works while the old cookie signs nobody in; a refused password signs nobody out; the button asks for the password, works with a driver that cannot name sessions, and on the database driver clears the other rows and keeps its own; the list shows only the account's own sessions and ends only the account's own; both password routes stop at five wrong tries a minute; and the check runs after the session starts and before a view as a customer. `ImpersonationTest.php` adds that two views as a customer on a site that checks the password leave the staff member signed in.

`tests/Feature/Settings/EmailChangeTest.php`: a new address is not taken without the password or with a wrong one, and the right one changes it, marks it unverified and sends the verification email, without storing the password typed as the password; a save that leaves the address alone asks for nothing (spaces and case do not count); a member whose address was made up is not asked and nothing is sent; the page says whether it asks; the old address is told (read from the mail the site really sent, to that address and no other, with the new one only in part), and a notice that cannot be sent does not undo the change; five wrong guesses stop the sixth, the count is shared with the password page and with deleting the account, and saves that do not change the address are not counted. `ProfileUpdateTest.php` has the two tests that change the address send the password.

The browser suite ([e2e-tests.md](e2e-tests.md)) does it with two real browsers.

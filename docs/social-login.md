# Signing in with Google, Discord and Steam

How to switch on the three sign-in buttons, what happens when somebody presses one, how an account is linked to a provider afterwards, and the few things that are easy to trip over.

> **Status:** built, with tests that use a fake provider (`tests/Feature/Auth/SocialLoginTest.php`, `SocialLoginEmailVerificationTest.php` and `OAuthProvidersTest.php`). **Not run** against a live Google, Discord or Steam application for this page; do that once with your own credentials before you rely on it.

The providers are Google, Discord and Steam. They are written for this site (`app/Support/OAuth`, registered in `App\Providers\OAuthServiceProvider`) rather than taken from a package, and each is one entry in `ProviderRegistry`.

## Setting up a provider

1. **Register the site with the provider and write down what it gives you.**
   - **Google:** an OAuth client in the Google Cloud console, with the People API enabled.
   - **Discord:** an application in the Developer Portal, with the `identify` and `email` scopes.
   - **Steam:** a Web API key from Steam.

2. **Register the return address, exactly.** The provider sends the person back to `https://your.site/auth/oauth/<provider>/callback`, and it has to be written the same way in the provider's dashboard and in your `.env` (the scheme, the host, no trailing slash). `.env.example` builds it from `APP_URL`, so a site that changes its address must change the registered address too.

3. **Put the credentials in `.env`.**

   ```ini
   GOOGLE_CLIENT_ID=...
   GOOGLE_CLIENT_SECRET=...
   GOOGLE_REDIRECT_URI="${APP_URL}/auth/oauth/google/callback"
   DISCORD_CLIENT_ID=...
   DISCORD_CLIENT_SECRET=...
   DISCORD_REDIRECT_URI="${APP_URL}/auth/oauth/discord/callback"
   STEAM_API_KEY=...
   STEAM_REDIRECT_URI="${APP_URL}/auth/oauth/steam/callback"
   ```

   They are read through `config/services.php`. The [configuration reference](configuration.md#sign-in-with-google-discord-and-steam) lists each one.

4. **Switch off the providers you do not use.** **Admin → System settings → OAuth Providers** has a switch for each, and **all three are on by default**. A provider that is on gets a button on the sign-in and registration pages whether or not you set its credentials, and pressing it then fails. A provider that is off has no button, and its `/auth/oauth/<provider>/redirect` and `/callback` addresses answer 404. The change takes effect at once, with no deploy.

Steam works differently from the other two: it does not use OAuth 2 but its OpenID sign-in, which wants the return address to match exactly and the domain to be known to Steam. Moving the site to another host name means changing `STEAM_REDIRECT_URI` and the domain in Steam's dashboard, or the sign-in ends in a 403.

## What happens when somebody presses a button

**Somebody who is not signed in** is sent to the provider, comes back, and then:

1. If this provider account is already linked to a member, that member is signed in.
2. Otherwise, **if the provider says the email address is verified** and a member has that address, that member is signed in and the provider account is linked to them. An address the provider has *not* verified is never used to find a member, because anyone can type somebody else's address into a provider that does not check it.
3. Otherwise a new member is made: a nickname taken from the provider's name (made unique), a long random password that nobody knows, and the provider's picture. Their email is the provider's address when no other member has it; if the provider gives none (Steam never does), or the address is taken, it is a made-up `…@oauth.local` address that cannot receive mail. The address counts as **verified** only when the provider vouches for it; otherwise they confirm it by email like anybody else.

Then the usual rules apply: a banned member is refused with the reason ([Moderation](moderation.md)); a member with two-factor on is still asked for a code ([Two-factor authentication](two-factor.md)); what a guest did before signing in (a cart, an order) is carried over to the account ([The shop](commerce.md)).

**A member who is signed in** and presses the button on **Settings → Security** links that provider to their own account instead. A provider account that already belongs to somebody else is refused with a message. One member has at most one account for each provider.

## Linking and unlinking

- **A member** links and unlinks from **Settings → Security** (`/settings/security`). Only the enabled providers are offered. Unlinking is refused when it would leave them no way to sign in (see [Unlinking](#unlinking-and-the-last-way-in) below).
- **Staff** with the `users.acp.update` permission can attach a provider identity (its id, and optionally name, nickname, email and picture) to a member from **Admin → Users → Edit**, and remove one. The id cannot already belong to another member. Both are written to the [audit log](audit-log.md). Removing the member's last way in is refused with the reason, until staff press **Disconnect anyway**.

### Unlinking and the last way in

A member who signed up with a provider has a password nobody showed them (a long random one) and, for Steam and for a Discord account with no address, an email address that only looks like one. If they unlink the provider, nothing is left to sign in with. So the site checks first, and **unlinks only if the member would still have a way in**. It counts two things, because they are the two it can check:

1. **Another linked provider that is switched on** (Google, Discord or Steam; one that staff turned off under **Admin → System settings → OAuth Providers** does not count, and neither does a row for a provider the site no longer has).
2. **A real email address the member has verified.** Then **Forgot your password?** reaches them whether or not they ever chose a password. A made-up `…@oauth.local` address does not count, and neither does a real address nobody has confirmed.

If neither holds, the member sees "That is your only way to sign in" on **Settings → Security** and the provider stays linked. They connect another provider, or add a real address in **Settings → Profile** and confirm it, and then unlink.

The site cannot tell a password the member chose from the random one (it keeps no such fact, and a column for it would only be a guess for the accounts that already exist). So a member whose verified address is real can unlink their only provider even if they never set a password; they use **Forgot your password?** to get one. And a member who registered with a form and a password they know, but never verified the address, is refused until they verify it.

**Staff** can still do it. The same check runs, and when it fails the **Admin → Users → Edit** page shows the reason and a **Disconnect anyway** button, because staff have good reasons to cut off an account that is not the member's (a provider linked by mistake, or by somebody else). Pressing it sends `force`; nothing else does. It is written to the [audit log](audit-log.md) like any other disconnect.

## What is kept from a provider

The site asks a provider one thing: who is this? It uses the access token the provider hands back once, to fetch the profile, and then lets it go. **No token is stored**: not the access token, not the refresh token, and not how long they last. The provider's raw profile is not stored either.

What the `social_accounts` table keeps for a linked account is the provider (`google`, `discord` or `steam`), the person's id there, and the name, nickname, email address and picture the site shows (the address is what the [privacy text](legal-pages.md) means by "the profile details that service shares with us"). A test fails if the table gets a column that is not on that list, so a token or a profile cannot be added by accident.

Nothing needs more. The Discord companion reads only the provider and the id to find a member's Discord account ([Discord companion](discord-companion.md)), and Steam never had a token. A feature that has to call a provider on the member's behalf later (adding them to a server, say) would need a token, and would need to store it encrypted (Laravel's `encrypted` cast, which makes what is stored unreadable if `APP_KEY` ever changes) and ask for it on purpose; that is a decision for that feature.

**A site that ran an earlier version** had the tokens and the raw profile in four columns (`access_token`, `refresh_token`, `token_expires_at`, `data`). The migration `stop_keeping_provider_tokens` empties them and drops them, as `php artisan migrate` runs it. Two things it cannot do:

- **A backup made before it still holds the tokens.** An access token stops working by itself soon (as the providers document it, Google's last an hour and Discord's a week), but a refresh token lasts until the person removes the application from their account at the provider. Keep such a backup as private as you did, or delete it when you no longer need it.
- **Rolling it back brings the columns back empty.** What was in them is gone, and no sign-in fills them again.

## Things to know

- **Unlinking the last way in is refused**, for members ([Unlinking and the last way in](#unlinking-and-the-last-way-in)). One thing follows for the people who run the site: **if you switch a provider off, members who only have that provider lose their way in**, and the guard does not see it coming (it only looks when somebody unlinks). Members with a real, verified address can still reset their password; the others cannot sign in until the provider is switched back on.
- **Signing in with a provider and two-factor.** A member who never chose a password cannot turn two-factor off, or replace their recovery codes, from the site, because both ask for the password ([Two-factor authentication](two-factor.md#turning-it-off)).
- **A made-up email is not a feature.** An address ending in `@oauth.local` should be treated as "no email". Mail to a member without a real one goes nowhere.
- **The Discord button is not the Discord bot.** Signing in with Discord only proves who the person is. Roles on a Discord server, and messages from the site to it, are the separate [Discord companion](discord-companion.md).

## Testing

`SocialLoginTest` signs in, registers and links through a fake provider, including the conflict and the disabled provider. It also signs in through the real Google and Discord provider classes with the provider's answers faked, and fails if a token, or a profile field the site does not use, is written to `social_accounts`; it fails for a new column on that table, for a token property on `OAuthUser`, and for a migration that leaves anything behind or cannot be run twice. `SocialLoginEmailVerificationTest` holds the rule about verified addresses (an unverified address does not find an account and does not mark a new one verified). `OAuthProvidersTest` holds the switches in the admin area and the 404s. `UnlinkLastSignInMethodTest` holds the rule for members (a made-up address, an unconfirmed address, another provider that is off or no longer exists are refused; a confirmed real address and another provider that is on are allowed; the refusal lifts once the address is confirmed), and `UserSocialAccountTest` holds the staff version (refused with the reason, `force` removes it, the permission is still needed, another member's account is a 404).

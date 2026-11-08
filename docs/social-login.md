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

- **A member** links and unlinks from **Settings → Security** (`/settings/security`). Only the enabled providers are offered.
- **Staff** with the `users.acp.update` permission can attach a provider identity (its id, and optionally name, nickname, email and picture) to a member from **Admin → Users → Edit**, and remove one. The id cannot already belong to another member. Both are written to the [audit log](audit-log.md).

## Things to know

- **Unlinking is not guarded.** Nothing stops a member who signed up with a provider from unlinking it. Their password is a random one they never saw, and a made-up `@oauth.local` address cannot receive a reset email, so they would be locked out. Tell members to set a real password and email in **Settings** first, or have staff attach the provider again from the admin area.
- **The provider's tokens are kept, and not encrypted.** The access and refresh tokens the provider returns are saved in the `social_accounts` table as they come, next to the profile it sent, and the site never uses them again after the sign-in. The scopes asked for only identify the person, so a leaked token reads little, but a database backup holds them. Treat the table, and any backup of it, as private. Storing them is a gap, not a decision: nothing in this application reads them.
- **A made-up email is not a feature.** An address ending in `@oauth.local` should be treated as "no email". Mail to a member without a real one goes nowhere.
- **The Discord button is not the Discord bot.** Signing in with Discord only proves who the person is. Roles on a Discord server, and messages from the site to it, are the separate [Discord companion](discord-companion.md).

## Testing

`SocialLoginTest` signs in, registers and links through a fake provider, including the conflict and the disabled provider. `SocialLoginEmailVerificationTest` holds the rule about verified addresses (an unverified address does not find an account and does not mark a new one verified). `OAuthProvidersTest` holds the switches in the admin area and the 404s.

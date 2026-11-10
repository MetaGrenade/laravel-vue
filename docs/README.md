# MetaForger documentation

Every guide in this folder, grouped by what you are trying to do. A test fails when a page is added here and not listed, so the list is complete. The project's [README](../README.md) says what MetaForger is and how to start; the [CHANGELOG](../CHANGELOG.md) says what changed.

## Start here

What the project is, and how to work on it.

- [What is in MetaForger](features.md): the technology it is built on and every module it ships with, with a link to the page for each.
- [Developing on your own machine](development.md): starting the site while you work on it, the backing services in Docker, the commands, and a few things about the code that save time.

## Run a site

Putting a site on a server and keeping it there, in the order you will need them.

- [Installing](install.md): the one command that sets up a fresh clone, its options for scripts and CI, running it again, and what to do when it stops.
- [Deploying](deployment.md): a server you run, or Forge: what a live site is made of, the first deploy, every deploy after it, a proxy or load balancer, backups, and what to do when something is wrong.
- [Running in Docker](docker.md): the image and the compose file for one machine: the first start, every update, HTTPS, backups and what to do when it does not start.
- [Configuration](configuration.md): every setting the site reads from `.env`, with its default and what it does.
- [Upgrading](upgrading.md): moving a running site to a newer version: what to back up, the steps that come after the migrations, what behaves differently, and how to go back.
- [Payments](payments.md): taking money with Stripe or Tebex, from the first test to going live.
- [Running on Redis](redis.md): the cache, the queue and the sessions on Redis, and the queue worker.
- [Live updates (Pusher)](realtime.md): pushing notifications and new forum replies to an open browser, and what to check when nothing arrives.
- [Running on Octane](octane.md): what a long-lived worker keeps from one request to the next.
- [Performance](performance.md): how fast a guest's page starts to answer, how to measure it on your own site, and what the number depends on.
- [Documents and caching](http-caching.md): the files that are the same for everyone, and the headers that let a cache keep them.
- [How the site protects itself](security.md): what is done about security without being asked, from cleaned user content to rate limits, and where each part is written up.
- [Security headers](security-headers.md): the headers every page carries and how to add a host to the Content Security Policy.
- [Reporting a vulnerability](security-contact.md): how a security problem is reported in private.

## Make it yours

Changing how the site looks, what language it speaks, and what it can do.

- [Theming and rebranding](theming.md): colours, fonts, logo, name, words and links, and a rebrand in order.
- [Search engines and sharing](seo.md): the title, description and picture of a page, the sitemap and `robots.txt`, when the site is indexed, and how to describe a page of your own.
- [Error pages](error-pages.md): what somebody sees when the server refuses what they asked for, and how to change it.
- [Adding a language](adding-a-language.md): how to make the site speak another language, and how it decides which one a person sees.
- [Internationalisation](i18n.md): how the translation files, the locale and the completeness check fit together.
- [Extending MetaForger](extending.md): where each kind of thing goes, which page has the recipe for each usual job, and which test tells you what you forgot.
- [How it is put together](architecture.md): the parts, how a request travels through them, and where each is explained.
- [The API](api.md): every endpoint under `/api/v1`, who may call it, and what it answers.
- [API keys](api-keys.md): making a key, its scopes, limits and expiry, and rotating one.
- [Webhooks](webhooks.md): how Stripe and Tebex tell the shop what happened to a payment, and how a delivery is kept.

## The shop and subscriptions

- [Commerce](commerce.md): how the shop takes an order from a cart to a paid order, and how to extend or replace parts of it.
- [Billing: subscriptions](billing.md): plans, what a customer is told when a payment fails, and the Stripe events the site needs.
- [Tebex](tebex.md): selling through Tebex as a merchant of record.
- [Entitlements](entitlements.md): what a purchase or a plan gives an account, a feature or a limit.
- [Metered usage](usage.md): counting what a customer does in a month and holding them to their plan.
- [Who may see an order, a ticket or a product](record-policies.md): the rules that follow the owner of a record.
- [Ownership](ownership.md): who a record belongs to, and what changes when teams arrive in 1.1.

## The forum

- [Pictures and files](forum-attachments.md): attaching a picture or a file to a post.
- [Thread prefixes](forum-prefixes.md): a short label on a thread.
- [Solved answers](forum-solutions.md): marking the reply that answers a thread.
- [Ignore list](forum-ignores.md): choosing not to see another member.
- [Forum RSS](forum-rss.md): the public feed of published threads.
- [Automatic moderation](automod.md): rules, held and refused posts, and the review queue.
- [Warnings and bans](moderation.md): what a moderator can do to a member, and what the member is told.

## The blog

- [Blog covers](blog-covers.md): a post's cover picture and the sizes kept.
- [Blog permissions](blog-permissions.md): who may do what in the blog's admin screens.

## Members and community

- [Members](members.md): the public page for each account and the directory.
- [Following](member-follows.md): following another member.
- [Leaderboard](leaderboard.md): who has earned the most reputation.
- [Direct messages](direct-messages.md): private conversations between two members.
- [The "Get started" checklist](onboarding.md): the first steps a new customer is shown, and how to add one.
- [Notifications and the bell](notifications.md): how a notification reaches a member.
- [Email digests](email-digests.md): a day's community email gathered into one message.
- [Email design](email.md): the layout every email shares and the settings for it.
- [Two-factor authentication](two-factor.md): an authenticator code at sign-in, recovery codes, signing out other browsers, and the password asked before the email address changes.
- [Signing in with Google, Discord and Steam](social-login.md): switching on the buttons, what happens when somebody presses one, and linking an account afterwards.
- [Discord companion](discord-companion.md): role sync, bot webhooks and Discord notification opt-in.

## The support desk

- [Routing and service levels](support-assignment.md): the rules that send a ticket to an agent or a team, and what the site does with a ticket nobody has answered.
- [Support attachments](support-attachments.md): files on a ticket.
- [Support permissions](support-permissions.md): who may do what at the support desk.

## The admin area

- [Admin permissions](acp-permissions.md): the named permissions that guard the roles, the forum structure, trust and safety, badges, tokens and search.
- [Admin audit log](audit-log.md): every change made in the admin area, and who made it.
- [Read-only impersonation](impersonation.md): seeing the site as one customer sees it.
- [Legal pages](legal-pages.md): terms, privacy and cookie policies, and the cookie banner.
- [Search index](search-index.md): what is searched, how Meilisearch is set up, and how the index is rebuilt.

## Pictures and files

- [Pictures](images.md): the sizes that are kept, where they live, and a CDN.
- [Private uploads](private-uploads.md): files that are not pictures stay on a private disk.

## How it is checked

What the tests and CI hold in place, for someone who changes the code.

- [Browser tests](e2e-tests.md): the paths a person takes through the site, driven in a real browser.
- [Accessibility](accessibility.md): the axe check on the pages a visitor opens.
- [Query budgets](query-budgets.md): how pages are kept from getting slower as the site grows.
- [Indexes](indexes.md): every foreign key starts an index, and the lists that need one for their order.
- [Bundle budget](bundle-budget.md): the size of what the browser downloads.
- [Lighthouse](lighthouse.md): a report of how the first pages score.
- [Secrets scan](secrets-scan.md): CI reads the git history for a committed key or password.
- [Licences](licences.md): what MetaForger is built on, under which licences, and how the notices are kept.

## The plan

- [The v1.0.0 release plan](ROADMAP-v1.0.0.md): scope, milestones and what is done.

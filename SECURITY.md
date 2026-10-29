# Security Policy

## Supported versions

Security fixes are applied to the latest release. Until 1.0.0 is tagged, only the `main` branch is supported.

| Version | Supported |
|---------|-----------|
| `main` (pre-1.0) | Yes |
| Latest 1.x release (once published) | Yes |
| Older releases | No |

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Report it privately through GitHub: go to the repository's **Security** tab and choose **Report a vulnerability** (GitHub private vulnerability reporting). Include:

- what the issue is and which part of the application it affects
- steps to reproduce, or a proof of concept
- the version or commit you tested
- the impact you believe it has

You can expect an acknowledgement within a few days. We will keep you updated while we investigate, agree a disclosure date with you, and credit you in the release notes if you wish.

The same contact is published at `/.well-known/security.txt`, with this policy's address and an expiry that stays under a year. Change it with `SECURITY_CONTACT_URI` and `SECURITY_POLICY_URL`. See [docs/security-contact.md](docs/security-contact.md).

> Maintainers: private vulnerability reporting must be enabled under *Settings → Code security*. If you would rather publish a contact email, set `SECURITY_CONTACT_URI` to a `mailto:` address.

## Scope

In scope: the application code in this repository, its default configuration, and its documented deployment guidance.

Out of scope: vulnerabilities in third-party services (Stripe, Tebex, Pusher and similar), in dependencies that have no fix available upstream (report those upstream), and issues that require a compromised server or administrator account.

## Hardening notes for deployers

- Keep `APP_DEBUG=false` and set `APP_ENV=production` in production.
- Serve over HTTPS and enable HSTS (`SECURITY_HSTS_*` in `.env`).
- Store user uploads on a private disk. Support files, forum files and product downloads refuse a disk that is public (`SUPPORT_ATTACHMENT_DISK`, `FORUM_ATTACHMENT_DISK`, `COMMERCE_DOWNLOAD_DISK`). See [docs/private-uploads.md](docs/private-uploads.md).
- Run `composer audit` and `npm audit` regularly; CI runs both on every change. Dependabot opens a pull request each week for Composer, npm, and GitHub Actions.
- CI reads every commit on the branch for a committed secret. See [docs/secrets-scan.md](docs/secrets-scan.md).
- Rotate `APP_KEY`, payment-provider secrets and webhook secrets if they may have been exposed.

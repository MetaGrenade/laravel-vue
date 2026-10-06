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

> Maintainers: private vulnerability reporting must be enabled under *Settings → Code security*. If you would rather publish a contact email, add it here and in `public/.well-known/security.txt`.

## Scope

In scope: the application code in this repository, its default configuration, and its documented deployment guidance.

Out of scope: vulnerabilities in third-party services (Stripe, Tebex, Pusher and similar), in dependencies that have no fix available upstream (report those upstream), and issues that require a compromised server or administrator account.

## Hardening notes for deployers

- Keep `APP_DEBUG=false` and set `APP_ENV=production` in production.
- Serve over HTTPS and enable HSTS (`SECURITY_HSTS_*` in `.env`).
- Store user uploads on a private disk. Support attachments already use one (`SUPPORT_ATTACHMENT_DISK`).
- Run `composer audit` and `npm audit` regularly; CI runs both on every change.
- Rotate `APP_KEY`, payment-provider secrets and webhook secrets if they may have been exposed.

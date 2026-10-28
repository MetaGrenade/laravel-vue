# Reporting a vulnerability

A security problem is reported in private. It is not opened as a public issue.

> **Status:** `SECURITY.md`, `/.well-known/security.txt`, and Dependabot are published.

## How to report

The policy is [SECURITY.md](../SECURITY.md). Use the repository's **Security** tab and **Report a vulnerability**. Say what is wrong, how to reproduce it, the version you tried, and the impact. An acknowledgement is expected within a few days.

## security.txt

`GET /.well-known/security.txt` names that same contact, the policy, the address of the file itself, and an expiry 364 days ahead, so the published file does not go stale. It stays available while the site is in maintenance. The contact is `SECURITY_CONTACT_URI` and the policy is `SECURITY_POLICY_URL`. A value with a line break is left out, so it cannot add another field. The file is plain text. It is one of the pages staff can open while viewing the site as a customer.

## Dependency updates

Dependabot (`.github/dependabot.yml`) opens a pull request each week for Composer, npm, and GitHub Actions. CI also runs `composer audit` and `npm audit` on every change, and again each week.

## Testing

`tests/Feature/SecurityTextTest.php`: the file names the contact, the policy, its own address, and an expiry 364 days ahead, a policy value with a line break is left out, and the file is still served while the site is in maintenance. `tests/Unit/DependabotConfigTest.php`: Composer, npm, and GitHub Actions are each checked weekly.

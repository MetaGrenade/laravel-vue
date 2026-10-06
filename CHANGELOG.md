# Changelog

All notable changes to MetaForge are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The path to 1.0.0 is laid out in [docs/ROADMAP-v1.0.0.md](docs/ROADMAP-v1.0.0.md).

## [Unreleased]

### Added

- A `docs/ROADMAP-v1.0.0.md` release plan covering features, security, performance, payments (Stripe and Tebex), search and the milestones to 1.0.0.
- Authorised download route for support-ticket attachments (web and API) with a policy, plus a `support:attachments:privatize` command.
- CI jobs that run the test suite on MySQL 8 and PostgreSQL 16 in addition to SQLite, with a guard test that fails if a job runs on the wrong database.
- `SECURITY.md`, `CONTRIBUTING.md`, `.well-known/security.txt`, issue and pull-request templates, `CODEOWNERS` and Dependabot configuration.
- `compose.yaml` with the backing services for local development (MySQL, PostgreSQL, Redis, Meilisearch and Mailpit).
- Internationalisation infrastructure (English only for now): `config/i18n.php`, the `SetLocale` middleware, `useI18n()`, shared translations as Inertia props and `php artisan lang:check` (see `docs/i18n.md`).
- Static analysis with Larastan (level 5, with a baseline), a gitleaks secrets scan, a weekly dependency-advisory audit and a scheduled workflow that repeats the test suite in random order.
- Web manifest generated from configuration; home page SEO copy configurable through `seo.home.*` (`SEO_HOME_TITLE`, `SEO_HOME_DESCRIPTION`).

### Changed

- Support attachments are stored on a private disk (`SUPPORT_ATTACHMENT_DISK`, default `local`) instead of the public disk. A migration moves existing files.
- All text filters and search use case-insensitive `whereLike`, so they behave the same on MySQL, PostgreSQL and SQLite.
- The brand name now comes from one place (`APP_NAME`); the logo, web manifest and home page no longer hard-code it.
- Default `APP_NAME` in `.env.example` is `MetaForge`.

### Removed

- The unfinished `mysql_fulltext` search driver (it referenced FULLTEXT indexes that were never created). Meilisearch support is planned for 1.0.

### Fixed

- **Security:** support-ticket attachments were reachable by anyone with the URL.
- `SupportTicketMessage::ticket()` and `SupportTicketAudit::ticket()` looked for a nonexistent `ticket_id` column and always returned `null`.
- The blog status migration did nothing on PostgreSQL, where the old CHECK constraint rejected the `scheduled` status.
- Rolling back the blog status migration now turns `scheduled` posts into drafts first, so the rollback no longer fails once any post has used that status.
- Moving legacy support attachments to the private disk now keeps a row on its original disk when the original file cannot be deleted, so the move is retried and reported instead of looking complete while a public copy remains.

## Before the changelog

Earlier work is not itemised here; it is summarised by its pull requests:

- #246 Design refinements: backgrounds, animations and performance.
- #245 Modernised design system, layouts and pages with light and dark themes.
- #244 Upgrade to Laravel 13 and a modern front-end stack, with security, SEO and bug fixes.
- #243 Polls and surveys.
- #241 Comment spam protection and rate limiting.

[Unreleased]: https://github.com/MetaGrenade/laravel-vue/commits/main

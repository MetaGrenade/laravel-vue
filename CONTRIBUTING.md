# Contributing to MetaForge

Thanks for helping. This guide covers setting up, the checks a change must pass, and how to submit it. The direction of the project is described in [docs/ROADMAP-v1.0.0.md](docs/ROADMAP-v1.0.0.md); please read it before proposing large features.

## Security issues

Do not open public issues for vulnerabilities. Follow [SECURITY.md](SECURITY.md).

## Getting set up

Requirements: PHP 8.4+, Composer, Node.js (see `.nvmrc`), and SQLite (the default), MySQL 8 or PostgreSQL 16.

```bash
git clone https://github.com/MetaGrenade/laravel-vue.git
cd laravel-vue
composer setup        # installs dependencies, creates .env, migrates, builds assets
composer dev          # server, queue worker and Vite together
```

Optional backing services (MySQL, PostgreSQL, Redis, Meilisearch, Mailpit) are defined in `compose.yaml`:

```bash
docker compose up -d mysql redis mailpit       # pick what you need
docker compose --profile pgsql up -d pgsql     # PostgreSQL is opt-in
```

Then point `.env` at them. The defaults in `compose.yaml` match the values in the examples below.

## Checks

Run these before opening a pull request. CI runs the same.

```bash
vendor/bin/pint                 # PHP code style (add --test to only check)
php artisan test                # PHP tests (SQLite by default)
npm run format                  # Prettier (format:check to only check)
npm run lint:check              # ESLint
npm run types:check             # vue-tsc
```

### Testing on other databases

CI runs the suite on SQLite, MySQL 8 and PostgreSQL 16. To run locally on one of them, start it with `compose.yaml` and override the connection for a run:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=metaforge \
DB_USERNAME=postgres DB_PASSWORD=password EXPECTED_DB_DRIVER=pgsql php artisan test
```

`EXPECTED_DB_DRIVER` makes a guard test fail if the run silently used another database.

## Writing portable code

The application must work on MySQL, PostgreSQL and SQLite:

- Use `whereLike()` / `orWhereLike()` for text matching instead of `where(col, 'like', ...)`.
- Put engine-specific SQL behind a helper such as `App\Support\Database\Sql`, with a branch for each driver.
- Migrations that alter columns need a branch for every driver (the existing ones show how).
- Avoid raw SQL where the query builder can express the query.

## Conventions

- **PHP:** follow Pint's Laravel preset. Keep controllers thin; put reusable logic in `app/Support` or a dedicated class.
- **Authorisation:** every route that touches user data needs a policy, gate or permission check. Add tests for the allowed and forbidden cases.
- **Uploads:** store user files on a private disk and serve them through an authorised route. Never trust client-supplied names or media types.
- **Front end:** Vue 3 with TypeScript and Tailwind. Use the theme tokens (`bg-card`, `text-muted-foreground`, `bg-highlight` and so on) rather than raw colours so light and dark modes both work. Check pages at phone width.
- **Tests:** add tests with the change. Prefer feature tests for behaviour and unit tests for pure logic.
- **Commits:** a short imperative subject line, then a body explaining why. Keep commits focused.

## Pull requests

1. Branch from `main` (`feat/...`, `fix/...`, `chore/...`).
2. Keep the change focused; split unrelated work into separate pull requests.
3. Fill in the pull request template, update `CHANGELOG.md` under **Unreleased**, and make sure CI is green.
4. Describe how you tested it, including any manual browser checks.

## Licence

By contributing you agree that your contributions are licensed under the project's [MIT licence](LICENSE.md).

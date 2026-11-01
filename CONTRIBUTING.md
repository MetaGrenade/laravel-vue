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
npm run build && npm run bundle:check   # the size of the front end against its budget
npm run bundle:test             # tests of the scripts in scripts/ (the size check, the time-to-first-byte check)
```

### Testing on other databases

CI runs the suite on SQLite, MySQL 8 and PostgreSQL 16. To run locally on one of them, start it with `compose.yaml` and override the connection for a run:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=metaforge \
DB_USERNAME=postgres DB_PASSWORD=password EXPECTED_DB_DRIVER=pgsql php artisan test
```

`EXPECTED_DB_DRIVER` makes a guard test fail if the run silently used another database.

### Testing on Redis

The suite runs on the array cache, the sync queue and array sessions. The `redis (Redis 7)` CI job runs `tests/Live/Redis` against a real Redis; they skip themselves unless `REDIS_LIVE_HOST` is set, and they empty the Redis they run against, so give them one of their own (docs/redis.md):

```bash
docker run --rm -d --name metaforge-redis-live -p 6390:6379 redis:7-alpine
REDIS_LIVE_HOST=127.0.0.1 REDIS_LIVE_PORT=6390 php artisan test tests/Live/Redis
docker stop metaforge-redis-live
```

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
- **Front end:** Vue 3 with TypeScript and Tailwind. Use the theme tokens (`bg-card`, `text-muted-foreground`, `bg-highlight` and so on) rather than raw colours so light and dark modes both work. Check pages at phone width. CI holds the built front end to a size budget (what every page loads, what each page adds, the largest script); import a heavy library where it is used and only when it is needed (the rich-text editor behind a condition or a dialog is `LazyRichTextEditor`), and if growth is on purpose raise the number in `scripts/bundle-budget.json` in the same change ([docs/bundle-budget.md](docs/bundle-budget.md)).
- **Tests:** add tests with the change. Prefer feature tests for behaviour and unit tests for pure logic.
- **Queries:** a list loads what it shows for the whole list (`with()`, `withCount()`), never for each row. The suite fails a test that lazy-loads a relation out of a list, and the pages people use most have a query budget that fails when a page asks something once per row or asks more than it is allowed. When a page legitimately needs another query, raise its ceiling in the same change ([docs/query-budgets.md](docs/query-budgets.md)).
- **Foreign keys:** give the column an index where the table is made, `$table->foreignId('user_id')->index()->constrained()`. MySQL adds one for you; PostgreSQL and SQLite do not, and without it every page that asks for the rows of a parent, and every delete of the parent, reads the whole table. `ForeignKeyIndexTest` fails on any database when one is missing ([docs/indexes.md](docs/indexes.md)).
- **Sorted lists:** a list of a table that grows (`orderBy` with a `limit`) needs an index that starts with the columns it filters with `=` and ends with the columns it sorts by, tie-break included; a foreign key index finds the rows and does not order them. Measure first with `EXPLAIN ANALYZE` (PostgreSQL) or `EXPLAIN` (look for `Using filesort`), and do not index a column that changes on every view or vote. Never start one with a foreign key column. `ListOrderIndexesTest` and [docs/indexes.md](docs/indexes.md#the-order-of-the-lists) say how.
- **Documents:** a file that is the same for every reader (a feed, a text file, a schema) is declared with `PublicDocuments::cachedFor($seconds)` and gets a line in `PublicDocumentsTest::documents()`. It starts no session, so it must not read one or the signed-in member, and a cache may keep it ([docs/http-caching.md](docs/http-caching.md)).
- **Refusals:** `abort(403, '...')` puts that message in front of the person who was refused (on the Error page, or as a notice when it was a button or a form), so write it for them: what they cannot do and, if it helps, why. Throw anything else and they see only the words for the status. A visit inside the app is answered by `App\Support\Errors\ErrorPages` ([docs/error-pages.md](docs/error-pages.md)); a new status needs `errors.hints.*` and `errors.actions.*` entries, and `ErrorPagesTest` fails until it has them.
- **Commits:** a short imperative subject line, then a body explaining why. Keep commits focused.

## Pull requests

1. Branch from `main` (`feat/...`, `fix/...`, `chore/...`).
2. Keep the change focused; split unrelated work into separate pull requests.
3. Fill in the pull request template, update `CHANGELOG.md` under **Unreleased**, and make sure CI is green.
4. Describe how you tested it, including any manual browser checks.

## Licence

By contributing you agree that your contributions are licensed under the project's [MIT licence](LICENSE.md).

## What and why

<!-- A short description of the change and the problem it solves. Link issues with "Closes #123". -->

## How it was tested

<!-- Tests added or run, and anything checked by hand (browser, light/dark mode, mobile width). -->

## Checklist

- [ ] Tests added or updated, and `php artisan test` passes
- [ ] `vendor/bin/pint`, `npm run format:check`, `npm run lint:check` and `npm run types:check` pass
- [ ] New queries work on MySQL, PostgreSQL and SQLite (use `whereLike`, the `Sql` helper; avoid engine-specific SQL)
- [ ] User-facing text is written for translation (see `docs/` once available) and works in light and dark mode
- [ ] New uploads, endpoints and webhooks are authorised, validated and rate limited as appropriate
- [ ] `CHANGELOG.md` updated under **Unreleased**
- [ ] Documentation updated if behaviour or configuration changed

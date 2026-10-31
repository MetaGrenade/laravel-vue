# Query budgets

How the test suite keeps pages from getting slower as the site grows: a lazy load fails the test that did it, and the pages people use most are held to a number of database queries that does not depend on how many rows they list.

> **Status:** both are in place and run with the rest of the suite. 36 pages have a budget (the list is in `tests/Feature/Performance`). Not built: a budget for every page, a budget on the time a page takes (that is the Lighthouse and TTFB work in the roadmap), and a check on queries that run outside a request (the queue, scheduled commands).

## Why

A page can be slow in two ways that a test of its output never shows:

- It asks one query for each row it lists (an N+1). With ten rows nobody notices; with ten thousand the page times out. Eloquent's lazy loading is the usual way this happens, but an explicit `->count()` or `->first()` inside a loop does the same and Eloquent cannot see it.
- It asks more queries than it needs, a few at a time, release after release.

## Lazy loading fails the suite

`Tests\TestCase` turns on `Model::preventLazyLoading()` for every test. Reading a relation that was not loaded, from a model that came out of a list of more than one, throws `LazyLoadingViolationException` where it happens. It is also noted for the test, so a `try`/`catch (Throwable)` around the code that did it cannot hide it: the test fails when it ends and names the relation (`Cart::items`, ...).

The fix is to load the relation where the list is fetched: `with()`, `load()`, `withCount()`, or `->chaperone()` on a `hasMany` whose rows are given their parent. A model fetched on its own (`find()`, `first()`) may load what it needs, because one query for one model is not a loop.

The app itself is unchanged: it still only logs a lazy load, and only locally (`AppServiceProvider`). Production never fails a page for one.

## A page has a budget

`Tests\Support\CountsQueries::assertQueryCost()` asks for a page with one row of whatever it lists, then with six, and fails when:

- the number of queries is not the same. The failure lists the queries the six-row request asked more of, which is the loop; or
- the number is more than the page's ceiling.

```php
$this->assertQueryCost(
    'the wishlist',
    fn () => WishlistItem::factory()->for($user)->for($this->product())->create(),   // one more row
    fn () => $this->actingAs($user)->get(route('shop.wishlist'))->assertOk(),        // the page
    32,                                                                              // the ceiling
);
```

How it measures:

- The cache is emptied before each measured request, so a page that keeps its result for a few minutes (the forum index) costs what building the result costs, which is what grows with the rows. A request that happens to be answered from the cache would say nothing.
- One unmeasured request comes first, for what every request loads once (permissions, route caches).
- Lists of ids (`in (1, 2, 3)`) are written `in (?)` so two requests that fetched a different number of rows by id still read the same.
- Every row the page lists should be a different one: a new author for each reply, a new category for each post. A test that adds six rows by the same author passes for a page that asks for the author of each.

### Choosing a ceiling

A ceiling is not a target. It is what the page costs today plus a quarter, so that a page that starts asking twice as much fails instead of getting slower unnoticed. When a page needs another query for a reason, raise its number in the same change and say why in the pull request. When a page needs fewer after a fix, lower it.

The ceilings were measured on SQLite. MySQL and PostgreSQL ask the same queries, and the room covers the odd one they add.

### Adding a page

Add a test to `QueryBudgetTest` (or `QueryBudgetShopTest` when the page needs a payment provider). Make the closure that adds a row add one of every kind of thing the page lists, so each relation it loads is tried. Run it with a high ceiling first to see the number, then set the ceiling.

## What it found

Written against the pages as they were, the budgets and the lazy-loading mode found three loops that the existing tests could not see:

- **The cart and checkout priced every line with two queries** (whether its product is sold in options, and its cheapest price). `OrderPricer` now fetches both for the whole cart (`PriceResolver::resolveLines()`), so a cart costs the same queries for one line or twenty. The price of a line is unchanged; two prices of the same amount are now resolved to the older one every time.
- **The Following page and the leaderboard's activity list counted the posts before each post** to work out which page of its thread the post is on. `App\Support\Forum\PostPages` places every post of a list in one query, and the notification of a followed member's reply uses the same class, so the three can no longer disagree.
- **Merging a guest's cart into an account's** read the cart and the package of each line one at a time. The lines are given their cart and their packages when the cart is loaded.

## Known costs

Not loops, but the largest single cost the budgets show on every page: the app reads its settings (maintenance mode, website sections, email verification, the payment provider, legal pages and so on) with one query each, eight or so on every request. A cache of them, cleared when a setting is saved, would take most of it away. It is not built because every test that writes a setting would have to go through it.

## Tests

`LazyLoadingModeTest` (the mode fails a loop, and fails a caught one, and lets a single model load what it needs), `QueryCostHelperTest` (the check can fail: a loop, a ceiling, and the cache), `PostPagesTest`, `PriceResolverLinesTest`, and the budgets themselves in `QueryBudgetTest` and `QueryBudgetShopTest`.

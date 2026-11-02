# Licences of what MetaForge is built on

MetaForge is MIT ([LICENSE.md](../LICENSE.md)) and is built on other people's software. Each piece is used under its own licence, and most of those licences ask for one thing when the software is passed on: that the copyright line and the licence text stay with it. [THIRD-PARTY-NOTICES.md](../THIRD-PARTY-NOTICES.md) is that notice, and a check in CI keeps it true.

```bash
npm run licences:check     # fails on a licence the policy does not allow, or when THIRD-PARTY-NOTICES.md is out of date
npm run licences:notices   # writes THIRD-PARTY-NOTICES.md
```

Both need the packages installed (`composer install` and `npm ci`), because the licence texts are read from `vendor/` and `node_modules/`.

## What is covered

| | What | Where it is read from |
| --- | --- | --- |
| PHP | The packages `composer install --no-dev` installs | `composer.lock`, `vendor/<package>/` |
| JavaScript | Everything `dependencies` in `package.json` reaches, and what those packages need | `package-lock.json`, `node_modules/<package>/` |
| JavaScript, built in | `tailwindcss` and `tw-animate-css`, which are build tools whose CSS ends up in the stylesheet (`alsoShipped` in the policy) | the same |
| Fonts and images | Outfit, Source Sans 3 and the technology logos on the home page | `assets` in the policy, written by hand |

What only builds or tests the project (`devDependencies`, Composer's `require-dev`, Playwright, ESLint) is not shipped, so it is neither checked nor listed. If one of them ever starts to put its own code or files into what visitors receive, add it to `alsoShipped`.

The project's own packages (`metagrenade/metaforge-discord`) are not third-party. They are named under `firstParty` in the policy and left out.

## The policy

`scripts/licence-policy.json` holds every decision, and nothing else in the check is a judgement.

- **`allowed`**: the licences that need nothing from the project beyond the notice: MIT, ISC, BSD-2-Clause, BSD-3-Clause, Apache-2.0, 0BSD, Unlicense and CC0-1.0. A package that offers a choice (`MIT OR Apache-2.0`, or nette's `BSD-3-Clause`, `GPL-2.0-only` or `GPL-3.0-only`, of which the notices show BSD-3-Clause) is used under the first allowed alternative, in the order it lists them. `A AND B` needs both to be allowed.
- **`exceptions`**: a package under a licence that is not allowed, one by one, each with the licence it is an exception for and the reason. The exception stops matching when the package changes its licence, so a new licence is a new decision. An exception for a package that is no longer shipped fails the check, so none stays behind.
- **`aliases`**: names old packages use for a licence (`Apache 2.0` for `Apache-2.0`).
- **`fallbackTexts`**: for a package that ships no licence file. The copyright line is copied from where the package's authors state it (`source`), and the text is the standard MIT text the script holds. Only MIT has one; another licence needs the script to learn its text.
- **`assets`**: fonts and images, which no lock file lists.

A package under a licence that is neither allowed nor excepted fails `npm run licences:check` with its name, version and licence. **A new licence is not something to add to the list to make CI green.** Weak copyleft (LGPL, MPL, EPL) can be fine when the library is used as installed and unmodified, and strong copyleft (GPL, AGPL) is not compatible with shipping this project under MIT. Decide, write the reason, and put it in `exceptions` if the answer is yes.

### The four exceptions today

| Package | Licence | Why it is here |
| --- | --- | --- |
| `dompdf/dompdf`, `dompdf/php-font-lib`, `dompdf/php-svg-lib` | LGPL-2.1, LGPL-2.1-or-later, LGPL-3.0-or-later | Laravel Cashier draws the PDF of a billing invoice with them (the default `cashier.invoices.renderer`), for the **Download PDF** link on the billing invoices page (`settings.billing.invoices.download`). |
| `elkjs` | EPL-2.0 | A dependency of `@unovis/ts`. Nothing in the built front end imports it. |

For the three LGPL libraries: they are used as installed from Packagist, unmodified, so what the licence asks for is the notice, the licence text and that a user can replace the library. All three are in the notices, and Composer is how the library is replaced (`composer update dompdf/dompdf`), or the invoice can be drawn by another class (`cashier.invoices.renderer`). If the project ever wants no LGPL code in it, the way out is a different `InvoiceRenderer`, which also has to be written.

## THIRD-PARTY-NOTICES.md

The file is generated. It has, in this order: the count per licence, the packages that needed an exception and why, one table per ecosystem (package, licence, copyright lines, and a link to the text), the fonts and images, and each distinct licence text once.

- **Versions are left out.** A routine update (Dependabot opens them weekly) does not change the file. What does change it is a package that comes or goes, a licence that changes, and a copyright line or licence text that changes (a new year in a package's copyright line is a change to its row).
- **A licence text is shown once** for all the packages that share it, without the copyright lines, which are in the table next to the package they belong to. Two packages share a text when it is the same after whitespace and capital letters are ignored. A text with a name in the body (BSD-3-Clause names the organisation, for one) is its own entry.
- **Nothing depends on the machine.** The order is by name in plain code-unit order, line endings are normalised, and there are no dates. The same lock files and the same package contents give the same file on Windows and Linux. An optional package that is not installed on the machine (a build for another platform) is skipped with a line saying so; none of the shipped packages is one today.

When the check says the file is out of date, run `npm run licences:notices` and commit the result. It tells you which packages came or went.

### Adding a dependency

1. Add it, and run `npm run licences:check`.
2. If it passes, it fails only because the file is stale: run `npm run licences:notices` and commit `THIRD-PARTY-NOTICES.md` with the dependency.
3. If it names a licence, that is the decision above. If it says the package has no licence file, find the authors' statement of the licence (the repository, the README), and put the copyright line in `fallbackTexts` with the address you read it at.

## Tests

`scripts/check-licences.test.mjs` runs with `npm run bundle:test` (CI runs it in the `bundle` job). It builds small projects in a temporary folder and checks, among other things:

- the expression reader (`OR`, `AND`, brackets, `WITH`, lower case, and an expression that cannot be read is an error and not a pass);
- which npm packages count as shipped: `devDependencies` are out, a package's own copy of a dependency is found before the one at the top, an optional peer the project installed for itself is not brought by the package, and a dependency that is not locked is reported;
- a licence that is not allowed fails with its name; an exception holds for the licence it was written for and not for the one the package moves to; a stale entry in the policy fails;
- a missing install names the command to run; a package without a licence file fails until the policy holds its text;
- the notices: shared texts, per-package copyright lines, two versions under two licences in one row, a `|` in a copyright line, a text that contains backticks, the same file in any input order, no versions in it;
- the command: `--write` makes the file, a package that came, went or changed its text fails the check by name, Windows line endings are accepted, and a failed audit writes nothing.

Each of 14 changes to the script (an unconditional "allowed", `OR` taking the last alternative, `AND` taking one part, the optional-peer rule removed, the line-ending rule removed, a stale exception not reported and so on) was made in turn to check that a test fails for it.

## What this does not do

- It trusts what a package says about itself. A package that names MIT and ships a different text in its `LICENSE` is not noticed; the text is shown in the notices as the package ships it.
- It does not check the licence of a package's own dependencies that it bundles into its files (code vendored inside a package). The npm and Composer metadata does not say.
- Trademarks are not a licence matter: the logos of the technologies on the home page are drawn from Simple Icons (CC0), but the marks belong to their owners. They are shown to say what the site is built with.
- It is not legal advice. Have a lawyer read it if the licence matters commercially (see the licence recommendation in [ROADMAP-v1.0.0.md](ROADMAP-v1.0.0.md)).

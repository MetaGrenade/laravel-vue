# Secrets scan

CI reads the git history for a committed key, token, or password. A find fails the check. The value is left out of the log.

> **Status:** gitleaks 8.24.3, on every push and pull request, and again each week.

## What is read

The secrets job in `.github/workflows/security.yml` checks out the whole history and runs `gitleaks detect` on every commit reachable from `HEAD`. A push to `main` or `develop` reads that branch. A pull request is checked out as the merge of the two branches, so the commits it adds are included. The weekly run reads `main` again, so a secret in an older commit is not left behind after the commit that added it is no longer the latest one.

Other branches that happen to be in the checkout are not read. The scan is `HEAD`, not every ref.

Four old commits are named in `.gitleaksignore`. One holds a made-up Tebex token that a test helper used to contain, one holds the published two-factor test vector, one holds placeholder Discord role ids (eighteen digits in a row) that the client-id rule matches, and one holds a made-up Meilisearch key that a test looked for in messages. None of them was a real credential. The Tebex token, the test vector and the Meilisearch key are not in a later commit. The fingerprint is what lets the whole history pass.

**A test that needs a made-up key** should not write one as a literal next to a name like `key`, `token` or `secret`: a string with enough different characters (the rule's bar is an entropy of 3.5) is taken for a real one, wherever it is, and a commit that holds it fails this scan for good, because the scan reads every commit. Build it from a repeated word instead, as `str_repeat('canary-', 3).'key'`, which is easy to look for and sits well under the bar. To see what the job will say before pushing, run the same scan: `gitleaks detect --source . --redact --log-opts="--full-history HEAD"`.

## The scanner has to notice a key

After the history is clean, the job writes a key into a temporary directory on the runner, from character codes so the workflow file itself does not contain one, and scans that directory. The characters vary, so the token sits above the entropy bar and is not skipped. If the scanner accepts it, the job fails. The file is removed and is never committed.

## Testing

`tests/Unit/SecretsScanWorkflowTest.php`: the job scans `--full-history HEAD`, still contains the check that a key is refused, and does not name the hosted action, a one-commit limit, every ref, or a key.

# Secrets scan

CI reads the git history for a committed key, token, or password. A find fails the check. The value is left out of the log.

> **Status:** gitleaks 8.24.3, on every push and pull request, and again each week.

## What is read

The secrets job in `.github/workflows/security.yml` checks out the whole history and runs `gitleaks detect` on every commit reachable from `HEAD`. A push to `main` or `develop` reads that branch. A pull request is checked out as the merge of the two branches, so the commits it adds are included. The weekly run reads `main` again, so a secret in an older commit is not left behind after the commit that added it is no longer the latest one.

Other branches that happen to be in the checkout are not read. The scan is `HEAD`, not every ref.

Three old commits are named in `.gitleaksignore`. One holds a made-up Tebex token that a test helper used to contain, one holds the published two-factor test vector, and one holds placeholder Discord role ids (eighteen digits in a row) that the client-id rule matches. None of them was a real credential. The Tebex token and the test vector are not in a later commit. The fingerprint is what lets the whole history pass.

## The scanner has to notice a key

After the history is clean, the job writes a key into a temporary directory on the runner, from character codes so the workflow file itself does not contain one, and scans that directory. The characters vary, so the token sits above the entropy bar and is not skipped. If the scanner accepts it, the job fails. The file is removed and is never committed.

## Testing

`tests/Unit/SecretsScanWorkflowTest.php`: the job scans `--full-history HEAD`, still contains the check that a key is refused, and does not name the hosted action, a one-commit limit, every ref, or a key.

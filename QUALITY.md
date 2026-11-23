# Portfolio verification

The projects use public, synthetic fixtures. A project README describes whether its source is an installable demo, an extracted example or a commercial case study. Run the project's documented command and check the current GitHub Actions run for the supported test matrix.

## Coding standards

PHP repositories use a pinned WordPress Coding Standards runner. The gate rejects new findings by file, rule and count. Each existing baseline records retained findings in the named file, including style in extracted source and standalone test contracts. When a finding is fixed, reduce or remove its entry. Third-party code in vendor directories is excluded from first-party style checks.

Behavioral tests cover stored order totals, shipping selection, a failed cart operation, stock conflicts, refund review, notification retries, access rules and migration. Local visual evidence uses the running fixture or the explicitly labeled sample, with synthetic products, orders and accounts.

## Third-party code

The MarginGuard excerpt includes a vendored Brick Math dependency and a provenance manifest. Hamsaz records browser and development packages in its npm lockfile. Shared test tooling records PHP and browser dependencies in lockfiles, with GitHub Actions pinned to commits. See each project's accompanying license and notice for the applicable scope.

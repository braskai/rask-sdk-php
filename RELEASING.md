# Releasing

Packagist takes a version from its tag by itself. A release is one run of the `Release` workflow, which makes the tag
only after the checks pass.

1. In a pull request, set the new version in `composer.json` and `src/Core/Version.php` (a test keeps them equal).
   Merge it.
2. Start the workflow on the default branch, with "Run workflow" on the Actions tab or from a terminal:

   ```sh
   gh workflow run release.yml
   ```

3. The workflow runs the CI checks, tags the commit `v` plus the version and makes the GitHub Release. Packagist reads
   the tag within a minute or two, and the workflow waits until Packagist lists the version.

The workflow refuses to run from any other branch, and refuses a version that is already tagged.
Do not push a version tag by hand: Packagist takes any tag, checked or not, and skips, without a word, one that differs
from `version` in `composer.json`.
A version already installed somewhere should not change: to fix one, release the next.

## Set up once

- Sign in at [packagist.org](https://packagist.org) with a GitHub account that administers this repository, and submit
  `https://github.com/braskai/rask-sdk-php`. The package name comes from `composer.json`: `braskai/rask-sdk`.
- Packagist then sets up its GitHub hook by itself. If the package page says the package is not auto-updated, it also
  says how to add the hook by hand.

The workflow needs no secrets.

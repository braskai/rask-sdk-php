# Releasing

Packagist takes a version from its tag by itself. A release is a GitHub Release.

1. In a pull request, set the new version in `composer.json` and `src/Core/Version.php` (a test keeps them equal).
   Merge it.
2. Publish a GitHub Release whose tag is `v` plus that version. Without `--target`, the tag goes on the default branch:

   ```sh
   gh release create v3.0.0-alpha.1 --generate-notes --prerelease
   ```

3. Packagist reads the tag within a minute or two. The `Release` workflow checks what Packagist cannot: that the tag is
   the version in `composer.json`, on the default branch, and that the tests pass on it. Then it waits until Packagist
   lists the version.

Packagist skips, without a word, a tag that differs from `version` in `composer.json`: the workflow fails on it instead.
A version already installed somewhere should not change: to fix one, release the next.

## Set up once

- Sign in at [packagist.org](https://packagist.org) with a GitHub account that administers this repository, and submit
  `https://github.com/braskai/rask-sdk-php`. The package name comes from `composer.json`: `braskai/rask-sdk`.
- Packagist then sets up its GitHub hook by itself. If the package page says the package is not auto-updated, it also
  says how to add the hook by hand.

The workflow needs no secrets.

# Developing and checking HerikaServer

## Environment

The application is PHP, not a native DLL. Use a separate development checkout and the PHP/Apache/PostgreSQL environment matching the supported DwemerDistro installation. Inspect its configuration before enabling optional services. PHPUnit 11 in `unittests/composer.json` requires PHP 8.2 or newer; the tests also need their relevant extensions and fixtures.

Use `php -v` and `php -m` to check the environment. PostgreSQL access uses `pgsql`; connector/package paths may need `curl`, `mbstring` and `zip`; PHPUnit needs its Composer-required extensions. Read `unittests/composer.lock` and the changed code for the exact requirements. There is no root Composer build command or portable all-in-one installer in this checkout.

## Checks from the repository root

```sh
php -l main.php
php -l lib/plugin_package_manager.php
git diff --check
```

Substitute the PHP files you changed. Lint checks syntax without invoking endpoints or mutating the database. `php main.php` is not a lint command and can enter real request processing.

For a targeted existing test, first inspect its setup and dependencies. In a disposable development environment:

```sh
cd unittests
composer install
php vendor/bin/phpunit --configuration phpunit.xml --filter ActionActorTargetRefTest
```

Stop if dependency installation or setup fails. Use the existing test relevant to the change; a passing isolated test is not proof of all server behavior. The tracked vendor directory is not a reason to update Composer dependencies during unrelated work.

## Database tests are destructive to their fixtures

Read `unittests/tests/DatabaseTestCase.php` and test configuration before running the suite. The current harness connects to a local PostgreSQL instance and drops/recreates `testdb` and `testdb_bkp`, and several tests write configuration fixtures. Run it only in an isolated environment with disposable databases and writable test paths. Do not run `unittests/run_tests` or the complete PHPUnit suite against an active Distro installation.

For migrations, use a fresh disposable database and a copy representing an older supported schema. Run the relevant update twice, verify preserved data and failure rollback, and keep `lib/playthrough_policy.php` consistent with any new table ownership. Report a missing test environment rather than resetting live state.

## Deployment and packaging

The parent monorepo's `scripts/deploy.ps1` is maintainer tooling outside this repository. A standalone clone does not contain it. Follow the installation's existing deployment procedure only when deployment is requested; preserve mutable paths, permissions and configured services. Copy `AGENTS.md`, `README.md` and `docs/` with the server source. Never archive a working runtime wholesale: it may contain keys, personal dialogue and databases.

For documentation-only changes, check relative links/source paths and extract a scratch archive to verify the text files. No provider call, database migration, native build or live deployment is needed. For runtime changes, report PHP checks, database probes, deployment and actual client/in-game validation separately.

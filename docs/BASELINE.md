# PlumberSlot Baseline

Recorded: 2026-07-28

## Versions

- Plugin: `0.1.0`
- Database schema: `2`
- Minimum PHP: `8.1`
- Minimum WordPress: `6.4`
- Local verification runtime: PHP `8.2.30`, Node `24.13.1`, npm `11.8.0`

## Source inventory

- `src/`: 61 PHP class/interface files
- `tests/`: 12 PHP test/bootstrap/probe/fixture/config files
- Plugin entry/lifecycle files: `plumberslot.php`, `uninstall.php`
- Generated PHP asset manifests: 2
- Total project PHP files excluding `vendor`, `node_modules`, and `reference-file`: 77

## Verified gates

- JavaScript production build: pass
- JavaScript lint: pass
- CSS lint: pass
- Playwright live suite: 6 pass (4 desktop/mobile runtime checks, same-start race, and different-start overlap race); 2 mobile duplicate races intentionally skipped
- PHP syntax lint: pass
- Composer manifest validation: pass
- PHPUnit unit suite: 8 tests, 19 assertions, all pass with no incomplete tests
- WordPress/MySQL integration suite: 22 tests, 165 assertions, all pass
- Booking overlap protection: full-range booking/hold occupancy plus a per-tutor MySQL advisory lock around the range check and insert; adjacent boundary starts remain allowed
- Hold security: the REST endpoint only creates holds for open slots, each token is bound to its owner, and the Local schema migration from version 1 to 2 is verified
- Booking and credit atomicity: booking insertion and conditional credit consumption share one transaction; an unavailable credit rolls the booking row back before notifications or hooks run
- Reschedule atomicity: the replacement booking and the original booking's `moved` transition commit together; a failed status update rolls the replacement row back before hooks or notifications run
- Cancellation/reschedule cleanup: old 24-hour and 1-hour reminder actions are unscheduled, replacement reminders are created after a move, and provider-scoped meeting cancellation clears stale references with up to three scheduled retries on failure
- Subject persistence: tutor-scoped create/read/update/delete operations use an explicit field allowlist, deterministic ordering, and cache invalidation; cross-tutor mutations are rejected
- Subject ownership integrity: booking requests resolve supplied subject ids inside the selected tutor's scope; foreign and missing ids return the same non-enumerable 404 and create no booking
- PHPStan level 6: pass
- PHPCS WordPress-Extra runtime policy: pass across 63 files
- Active Local runtime: WordPress 7.0.2, PlumberSlot active, DB version 2, REST route responsive

## Known blockers/debt

- This directory is not a Git repository, so a recoverable baseline commit cannot be created here.
- Live activation and REST boot are verified. Deactivation and default keep-data uninstall pass in isolation; opt-in destructive uninstall passes 46 assertions against a guarded disposable database and removes tables, options, roles/capabilities, cache, transient, and scheduled actions.
- Playwright supports the running Local site and an optional `PLUMBERSLOT_E2E_SERVER_COMMAND`. The opt-in booking-race fixture seeds isolated users, tutor availability, and a page; verifies one `201`, one `409`, and one database row; then cleans its rows and scheduled reminder side effects.
- PSR-4 filename exceptions are explicit. Full WordPress-Docs enforcement remains a separate Phase 8 task.
- The fail-closed integration bootstrap now uses `wp-phpunit/wp-phpunit` 7.0.2. Booking/hold/credit and destructive lifecycle integration tests pass against isolated `plumberslot_test*` databases.
- The in-app browser webview could not attach, so runtime verification used Playwright HTTP requests and direct Local HTTP/database probes.

## Design authority

Implementation must preserve the visual system and screen behavior in `reference-file/`. The generated build excludes that directory.

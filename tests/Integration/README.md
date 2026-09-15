# Integration test safety

WordPress integration tests reset database tables. Never point them at the
Local site's `local` database.

Required environment:

```text
WP_TESTS_DIR=/path/to/wordpress-tests-lib
WP_CORE_DIR=/path/to/wordpress
PLUMBERSLOT_TEST_DB_NAME=plumberslot_test
PLUMBERSLOT_TEST_DB_USER=root
PLUMBERSLOT_TEST_DB_PASSWORD=root
PLUMBERSLOT_TEST_DB_HOST=127.0.0.1:10156
PLUMBERSLOT_TEST_TABLE_PREFIX=ts_test_
PLUMBERSLOT_TEST_ALLOW_DESTRUCTIVE=1
```

Copy `tests/wp-tests-config.php.dist` to `tests/wp-tests-config.php`, then run
`composer test:integration`. The bootstrap exits before WordPress loads unless
the database name, prefix, config file, and explicit destructive-test flag are
all safe.

With that same isolated MySQL environment, `composer test:perf:slots-cached`
warms one real `/plumberslot/v1/slots` REST response and measures 25 subsequent
cache hits. The p75 application response time must stay below 20ms, and a query
probe proves the cached path does not read availability, exception, booking,
or slot-lock tables. DNS, HTTP-server startup, and TLS are intentionally outside
this server-side application budget.

`composer test:perf:slots-uncached` bumps the technician's cache generation before
each of 25 measured requests, outside the timer, so every REST dispatch must
read the real availability, exception, booking, and slot-lock tables and
recompute/serialize the slots. Its p75 application response budget is 200ms.

`composer test:perf:booking-post` acquires each hold outside the timer, then
measures 25 paid-service booking requests through real WordPress REST dispatch
and MySQL. The 300ms p75 synchronous budget includes nonce/capability/rate-limit
checks, service ownership, hold verification and consumption, slot/overlap
checks, transaction and booking insert, audit logging, reminder scheduling,
notification dispatch, and response serialization. It does not execute the
later asynchronous reminder or meeting jobs.

`composer test:perf:booking-calendar` seeds exactly 10,000 hourly confirmed
bookings outside the timer, proves the production calendar range query uses a
technician/end composite index, then reads and hydrates a 744-row month window 25
times. Its p75 MySQL repository budget is 500ms; fixture generation, HTTP, and
browser rendering are intentionally outside this database-query gate.

`composer test:perf:options-autoload` exercises fresh activation, rewrite setup,
onboarding completion, and a legacy v5 upgrade. Every persistent
`plumberslot_*` option must remain outside WordPress's autoload set, while the v6
migration preserves values and normalizes any legacy autoloaded rows.

After exporting the same safe integration environment, run
`composer test:phase8-lifecycle` as the repeatable Phase 8 exit gate. It covers
the frozen-schema upgrade, migration idempotency and data preservation, fresh
activation, deactivation cleanup, and scheduled-action group isolation.

The deactivation cleanup smoke runs the production `Deactivator` against real
WordPress and Action Scheduler state. It verifies runtime action, cache, and
data cleanup boundaries while preserving every table, option, role, and seeded
technician.

The companion isolation case schedules identical hook/argument actions in the
PlumberSlot and a foreign group, then proves deactivation removes only PlumberSlot's
action.

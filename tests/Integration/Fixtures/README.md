# Migration fixtures

`schema-v4.sql` is the frozen PlumberSlot database-version 4 contract used as the
starting point for upgrade tests. Version 4 was selected because it is the last
schema before migration step 5 introduced payment and webhook tables.

The snapshot intentionally includes subject status, lock ownership, and the
non-unique booking start index introduced by steps 2–4. It intentionally omits
`plumberslot_payments` and `plumberslot_webhook_events`.

Use `PreviousSchemaFixture::install()` only inside the isolated destructive
integration-test database. The installer replaces current PlumberSlot test tables
and sets `plumberslot_db_version` to `PreviousSchemaFixture::VERSION`.

`Phase8MigrationTest::test_version_four_migrates_to_the_current_schema()` then
runs the production `Migrator` and verifies the complete v5 table/index contract.
The companion idempotency test fingerprints every current column and index and
asserts that rerunning the migrator executes no DDL and changes no schema metadata.
A preservation test seeds representative v4 tutor, subject, credit, booking, and
lock records plus nested plugin settings, then compares exact before/after snapshots
around the production migration.

The fresh-install smoke removes every PlumberSlot table, option, transient, role, and
capability before invoking the production `Activator`. It verifies current empty
tables, exact defaults, critical schema metadata, and restored access roles.

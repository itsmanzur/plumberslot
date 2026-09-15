# PlumberSlot

PlumberSlot is a WordPress appointment-scheduling plugin for plumbing
businesses. It provides per-technician weekly availability, technician-owned
services, single and recurring bookings with a structured job-site address,
Service Plan credits, optional payments, virtual estimates and a role-aware
technician dashboard.

The project is currently an unreleased `0.1.0` pre-release. Installable release
artifacts have not been published yet.

## Requirements

- WordPress 6.4 or newer;
- PHP 8.1 or newer with the Sodium extension for encrypted credentials and
  signed meeting links;
- HTTPS for live payment callbacks and OAuth connections; and
- a working WordPress mail/cron setup for notifications and scheduled work.

## Source checkout setup

This repository contains development files, not a finished release ZIP.

```bash
composer install
npm ci
npm run build
```

Composer installs Action Scheduler and the PHP quality/test toolchain. The
production JavaScript and CSS files are generated in `assets/dist`.

Before storing third-party credentials, add a stable secret to `wp-config.php`:

```php
define( 'PLUMBERSLOT_ENCRYPTION_KEY', '<a long random secret>' );
```

PlumberSlot falls back to WordPress `AUTH_KEY` when the dedicated constant is not
defined. Do not change either key after credentials have been encrypted unless
you are prepared to reconnect every provider.

## Quality checks

```bash
composer lint
composer analyse
composer test
npm run lint:js
npm run lint:css
npm run test:unit:js
npm run build
```

The MySQL integration suite is destructive and must only use a dedicated
`plumberslot_test*` database and `ts_test_*` table prefix. See
[`tests/Integration/README.md`](tests/Integration/README.md) before running it.
Browser-test setup is documented in
[`tests/E2e/README.md`](tests/E2e/README.md).

## Architecture

- `src/Database/` owns custom tables, migrations and repositories.
- `src/Domain/` owns booking, recurrence, payment and meeting workflows.
- `src/Rest/` is the authorization and serialization boundary.
- `src/Frontend/` registers the booking block, shortcodes and dashboards.
- `src/Admin/` registers onboarding, PlumberSlot screens and settings.
- `assets/src/` contains Preact source; `assets/dist/` contains production
  bundles.

The Help & Docs product tour is hosted externally rather than bundled. Supply a
public YouTube, Vimeo or similar HTTPS watch-page URL with the
`plumberslot_help_video_url` filter; when it is empty, the page keeps the written
walkthrough and shows a clear coming-soon state.

The database stores booking times in UTC. Technician/customer timezones are
converted at the application boundary. Request IDs are never treated as
ownership proof; protected routes apply the relevant capability and account
relationship checks.

## Documentation

- [User guide](docs/USER-GUIDE.md)
- [Support and troubleshooting](SUPPORT.md)
- [Booking lifecycle policy](docs/BOOKING-LIFECYCLE-POLICY.md)
- [Security policy](SECURITY.md)
- [External-service disclosure](readme.txt)
- [Third-party licenses](THIRD-PARTY-LICENSES.txt)

WordPress.org-facing installation, FAQ, privacy and changelog content lives in
[`readme.txt`](readme.txt).

## Support and security

Use [GitHub issues](https://github.com/itsmanzur/plumberslot/issues) for
reproducible non-sensitive bugs. Follow [`SUPPORT.md`](SUPPORT.md) and remove
credentials or personal data before posting.

Report vulnerabilities privately to **security@plumberslot.com**. Do not publish
exploit details in an issue; follow [`SECURITY.md`](SECURITY.md).

## License

PlumberSlot is licensed under GPL-2.0-or-later. Bundled third-party notices are in
[`THIRD-PARTY-LICENSES.txt`](THIRD-PARTY-LICENSES.txt).

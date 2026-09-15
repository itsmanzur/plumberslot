# Live E2E fixtures

The booking fixture creates three reserved users, one tutor and subject, one
weekly availability row, and one temporary booking page. It removes those rows
in a `finally` block and also clears stale fixture rows before every run.

Because it writes to the target WordPress database, the same-slot and
overlapping-start two-browser races are opt-in:

```powershell
$env:PLUMBERSLOT_E2E_RACE_READY='1'
npm.cmd run test:e2e -- --project=chromium-desktop
```

The public happy-path test drives subject selection, slot selection, hold,
confirmation, completion UI, and the persisted booking in both desktop and
mobile projects. Each project receives isolated reserved fixture identifiers:

```powershell
$env:PLUMBERSLOT_E2E_HAPPY_READY='1'
npm.cmd run test:e2e -- tests/E2e/public-booking-happy-path.spec.js
```

Payment return coverage seeds paid-confirmed, cancelled-checkout, and failed
payment bookings, then verifies authenticated server state and recovery UI in
both desktop and mobile projects:

```powershell
$env:PLUMBERSLOT_E2E_PAYMENT_READY='1'
npm.cmd run test:e2e -- tests/E2e/payment-return.spec.js
```

Booking lifecycle coverage drives the linked manager's Bookings UI through
reschedule and cancellation, then verifies the persisted moved/new-confirmed
and cancelled states in both desktop and mobile projects:

```powershell
$env:PLUMBERSLOT_E2E_LIFECYCLE_READY='1'
npm.cmd run test:e2e -- tests/E2e/booking-lifecycle.spec.js
```

Onboarding coverage completes all four setup steps, verifies the completion
screen and REST payload, and checks the resulting subject, availability,
settings, booking shortcode, user meta, and audit log. Global settings changed
by the wizard are backed up and restored after each desktop/mobile project:

```powershell
$env:PLUMBERSLOT_E2E_ONBOARDING_READY='1'
npm.cmd run test:e2e -- tests/E2e/onboarding-wizard.spec.js
```

Availability visual coverage renders the authenticated timetable against
reviewed desktop and 390px mobile PNG baselines. Regenerate baselines only
after visually reviewing an intentional design change:

```powershell
$env:PLUMBERSLOT_E2E_VISUAL_READY='1'
npm.cmd run test:e2e -- tests/E2e/availability-visual.spec.js
npm.cmd run test:e2e -- tests/E2e/availability-visual.spec.js --update-snapshots
```

Public widget visual coverage renders the selected Subject step against
reviewed desktop and 390px mobile PNG baselines. It dismisses the site's
consent banner through its public control before capturing the PlumberSlot root:

```powershell
$env:PLUMBERSLOT_E2E_VISUAL_READY='1'
npm.cmd run test:e2e -- tests/E2e/public-widget-visual.spec.js
npm.cmd run test:e2e -- tests/E2e/public-widget-visual.spec.js --update-snapshots
```

Booking-state visual coverage captures the held Confirm form, failed-payment
recovery, and successful completion UI in desktop and 390px mobile. Dynamic
dates, booking references, and countdowns are normalized for stable baselines;
run this Local-site suite with one worker:

```powershell
$env:PLUMBERSLOT_E2E_VISUAL_READY='1'
npm.cmd run test:e2e -- tests/E2e/booking-state-visual.spec.js --workers=1
npm.cmd run test:e2e -- tests/E2e/booking-state-visual.spec.js --workers=1 --update-snapshots
```

Parent dashboard visual coverage seeds a confirmed child relationship, an
upcoming credit-paid lesson with a tutor note, and an active package. It
captures reviewed desktop and 390px mobile baselines with isolated cleanup:

```powershell
$env:PLUMBERSLOT_E2E_VISUAL_READY='1'
npm.cmd run test:e2e -- tests/E2e/parent-dashboard-visual.spec.js --workers=1
npm.cmd run test:e2e -- tests/E2e/parent-dashboard-visual.spec.js --workers=1 --update-snapshots
```

The 390px horizontal-overflow audit exercises Subject, Time, Confirm,
Completion, failed-payment recovery, Parent dashboard, and Availability. It
requires both the document and PlumberSlot root to remain inside the viewport;
the Availability timetable must scroll only inside its `.ts-tt-wrap`:

```powershell
$env:PLUMBERSLOT_E2E_VISUAL_READY='1'
npm.cmd run test:e2e -- tests/E2e/horizontal-overflow.spec.js --workers=1
```

Keyboard-only booking coverage reaches every widget control with Tab, uses
Arrow keys in the Subject and Time listboxes, types a note, and confirms with
Enter. It asserts that no pointer action occurred inside the widget, that the
completion heading receives focus, and that the confirmed database row exists:

```powershell
$env:PLUMBERSLOT_E2E_HAPPY_READY='1'
npm.cmd run test:e2e -- tests/E2e/keyboard-booking.spec.js --workers=1
```

Focus-ring coverage audits the computed keyboard-focus treatment across the
Subject, Time, and Confirm steps. Each tested control must expose a solid,
non-transparent outline at least 2px wide with a positive offset, even under
the active WordPress theme's resets:

```powershell
$env:PLUMBERSLOT_E2E_HAPPY_READY='1'
npm.cmd run test:e2e -- tests/E2e/focus-ring.spec.js --workers=1
```

Reduced-motion coverage emulates `prefers-reduced-motion: reduce` and scans
every PlumberSlot element plus its `::before` and `::after` styles in the
Subject, loading, Time, and Confirm states. Motion delays must be zero,
durations no more than 0.01ms, iterations at most one, and scrolling automatic:

```powershell
$env:PLUMBERSLOT_E2E_HAPPY_READY='1'
npm.cmd run test:e2e -- tests/E2e/reduced-motion.spec.js --workers=1
```

Bengali rendering coverage seeds Bengali tutor and subject content, checks the
configured cross-platform font fallback, detects replacement or uniform tofu
glyphs, and rejects clipped or overflowing text. Reviewed Subject and Confirm
baselines cover desktop and 390px mobile layouts:

```powershell
$env:PLUMBERSLOT_E2E_VISUAL_READY='1'
npm.cmd run test:e2e -- tests/E2e/bengali-rendering.spec.js --workers=1
npm.cmd run test:e2e -- tests/E2e/bengali-rendering.spec.js --workers=1 --update-snapshots
```

The reference-design contract loads the built public, admin, and dashboard CSS
without WordPress and compares browser-computed colour, spacing, radius, and
shadow values with the canonical HTML mockups. It runs in desktop and 390px
mobile projects and needs no database fixture:

```powershell
npm.cmd run build
npm.cmd run test:e2e -- tests/E2e/reference-design-contract.spec.js
```

The widget transfer budget measures the production JavaScript and CSS as the
two separately gzipped HTTP responses a browser receives. The combined size
must remain below 50 KiB and is enforced after the production build in CI:

```powershell
npm.cmd run build
npm.cmd run test:budget
```

Mobile LCP coverage uses a 390x844 viewport, warms server-side PHP/MySQL/opcode
caches, then disables and clears the browser cache for each of five measured
loads. The release gate requires p75 Largest Contentful Paint below 1.5s and
attaches the per-load navigation/LCP details to the test report:

```powershell
$env:PLUMBERSLOT_E2E_PERF_READY='1'
npm.cmd run test:perf:lcp
```

Admin first-render coverage logs in with an isolated lifecycle fixture, warms
the server, then disables and clears the browser cache for each of five loads.
It measures production PlumberSlot admin mount until preloaded dashboard data has
produced the first meaningful `Today` card, with a p75 release budget below one
second. Response start, DOMContentLoaded, absolute render, and response-to-render
timings remain attached so the surrounding WordPress admin shell and Local
server can be diagnosed without making the plugin gate environment-dependent.
Both desktop and 390px mobile projects run:

```powershell
$env:PLUMBERSLOT_E2E_PERF_READY='1'
npm.cmd run test:perf:admin-render
```

Timetable interaction coverage drags across mutable weekly cells and measures
each input-to-Preact-DOM-commit update (or a batch committed in one frame). The
desktop and 390px mobile p75 main-thread work must stay below the 16ms frame
budget; all individual measures and the slowest update are attached:

```powershell
$env:PLUMBERSLOT_E2E_PERF_READY='1'
npm.cmd run test:perf:timetable
```

Local defaults target `127.0.0.1:10156`, database `local`, user/password
`root`, and prefix `wp_`. Override them when the E2E WordPress site differs:

```text
PLUMBERSLOT_E2E_DB_HOST
PLUMBERSLOT_E2E_DB_PORT
PLUMBERSLOT_E2E_DB_NAME
PLUMBERSLOT_E2E_DB_USER
PLUMBERSLOT_E2E_DB_PASSWORD
PLUMBERSLOT_E2E_DB_PREFIX
PLUMBERSLOT_E2E_PHP_BINARY
PLUMBERSLOT_E2E_BROWSER_PATH
```

The fixture script is CLI-only. Browser requests still pass through the live
WordPress login, nonce, capability, REST validation, service, and MySQL layers.
`PLUMBERSLOT_E2E_BROWSER_PATH` can point Playwright at an existing Chrome/Chromium
binary when its bundled browser has not been installed.

## Release completeness gate

The live fixture specs use `test.skip` only as a destructive-run safety gate.
For a release run, set every matching `*_READY` variable to `1`; the public,
payment, lifecycle, and onboarding specs must execute in desktop and mobile.
The two-context race executes once in the desktop project by design, because
its concurrency protection is viewport-independent. PHPUnit configurations
also fail the run on incomplete, skipped, risky, or warning results.

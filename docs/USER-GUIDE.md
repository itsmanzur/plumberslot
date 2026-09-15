# PlumberSlot user guide

This guide describes the current unreleased `0.1.0` pre-release. Menu labels
and workflows may still change before the first public release.

## 1. Install and activate

PlumberSlot requires WordPress 6.4+ and PHP 8.1+. Install a complete release ZIP,
then activate **PlumberSlot** under **Plugins**. A source checkout also needs
`composer install`, `npm ci` and `npm run build` before it is feature-complete.

Activation creates PlumberSlot's database tables and the Tutor, Student and Parent
roles. The setup wizard opens once for a user who can manage PlumberSlot.

For optional payment or meeting credentials, first add a stable encryption key
to `wp-config.php`:

```php
define( 'PLUMBERSLOT_ENCRYPTION_KEY', '<a long random secret>' );
```

Keep this key outside the database and do not rotate it without reconnecting
configured services. WordPress `AUTH_KEY` is used as a fallback.

## 2. Finish onboarding

The four-step wizard asks for:

1. **Teaching mode** — solo tutor or coaching centre.
2. **Subjects** — choose suggested subjects or add your own.
3. **Availability** — paint the weekly cells students may book.
4. **Payments** — enable online payments now or configure them later.

Finishing setup creates or updates the tutor profile and publishes a **Book a
lesson** page containing the tutor-specific shortcode.

## 3. Configure the timetable

Open **PlumberSlot → Availability**.

- Paint open weekly cells and save the timetable.
- Add one-off closed dates for holidays or exceptions.
- Confirm the tutor timezone before publishing slots.
- Remember that lead time, lesson duration and buffer settings can remove an
  apparently open time from the public list.

PlumberSlot stores times in UTC and displays them in the applicable tutor/student
timezone.

## 4. Configure subjects

Open **PlumberSlot → Subjects**. Each subject belongs to one tutor and can define
its level, curriculum, duration, price and trial status. A booking request is
rejected if the requested subject does not belong to the selected tutor.

For multi-tutor sites, administrators manage tutor accounts under **PlumberSlot →
Tutors**. Tutors can manage their own availability, subjects and bookings but do
not receive site-wide settings access.

## 5. Publish booking

Use the **PlumberSlot booking** block, or add a shortcode:

```text
[plumberslot tutor="tutor-slug"]
```

Optional shortcode attributes:

```text
[plumberslot tutor="tutor-slug" subject="123"]
```

Visitors can browse the tutor, subject and open times. A WordPress account with
booking permission is required before a slot can be held or confirmed.

The booking flow supports one lesson or a recurring weekly series. A recurring
series may include multiple weekdays and a lesson count; an individual lesson
can later be moved without shifting the remaining series.

## 6. Student, parent and tutor access

PlumberSlot registers Student, Parent and Tutor roles.

- Parents link an existing student account, book for that learner and see family
  lessons and credit balances.
- Tutors manage their own schedule, bookings, attendance and lesson notes.
- Administrators manage all tutors and global settings.

Front-end dashboards are available at `/parent-dashboard/` and
`/tutor-dashboard/` after WordPress permalinks are active. They may also be
embedded with:

```text
[plumberslot_dashboard view="parent"]
[plumberslot_dashboard view="tutor"]
```

## 7. Payments and lesson credits

Open **PlumberSlot → Settings → Connections** to configure optional Stripe or
bKash credentials. Online payments remain unavailable until payments are
enabled and a gateway is fully configured.

- Stripe uses hosted Checkout; PlumberSlot does not collect raw card details.
- bKash Tokenized Checkout accepts BDT only.
- Sites may allow payment directly to the tutor.
- Lesson-package credits can be issued and spent against an eligible
  tutor/subject; package issuance is separate from gateway checkout in this
  release.

Configure gateway webhooks/callbacks on the site's canonical HTTPS URL. See the
[external-service disclosure](../readme.txt) before enabling a
provider.

## 8. Online meetings and notifications

Google Meet uses a tutor OAuth connection and Google Calendar. Zoom uses a
site-configured server-to-server OAuth application. PlumberSlot creates meetings
after eligible bookings are confirmed and sends participants a signed,
time-limited PlumberSlot join URL instead of the raw provider URL.

Email uses the site's WordPress mail configuration. Reminder jobs require
Action Scheduler and a functioning WordPress cron runner. SMS is not delivered
by PlumberSlot itself; an add-on must handle the `plumberslot_send_sms` action.

## 9. Manage a booking

Open **PlumberSlot → Bookings** to view lessons, reschedule or cancel, record
attendance, save lesson notes and export CSV data. State transitions are
restricted by role and the current booking status. See the
[booking lifecycle policy](BOOKING-LIFECYCLE-POLICY.md) for the supported
transitions and side effects.

## 10. Privacy, deactivation and uninstall

PlumberSlot registers WordPress personal-data export and erasure callbacks and
adds suggested text to the WordPress Privacy Policy Guide. Review that text and
the external-service disclosure before publishing the site's privacy policy.

Deactivation removes PlumberSlot scheduled actions but preserves data. Uninstall
also preserves data unless **Delete all PlumberSlot data when the plugin is
removed** was explicitly enabled in advanced settings before deletion.

## Getting help

Follow [`SUPPORT.md`](../SUPPORT.md) for common checks and safe diagnostic
details. Security issues must be sent privately according to
[`SECURITY.md`](../SECURITY.md).
